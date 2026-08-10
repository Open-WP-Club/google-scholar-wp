/**
 * Google Scholar Profile Display - browser-assisted import bookmarklet.
 *
 * Run this while viewing your own public Google Scholar profile page
 * (scholar.google.com/citations?user=...). It reads the page (and, same
 * origin, the following pagination pages) directly in your browser, then
 * copies the collected data to your clipboard so you can paste it into the
 * plugin's Import box in wp-admin. Nothing here talks to the WordPress
 * site directly - it only reads scholar.google.com and writes to the
 * clipboard.
 *
 * __MAX_PUBLICATIONS__ and __EXPAND_AUTHORS__ are replaced with the
 * plugin's configured "Max Publications" and "Full Author Lists" settings
 * when this file is turned into the javascript: bookmarklet link on the
 * settings page.
 */
(function () {
  var MAX_PUBLICATIONS = __MAX_PUBLICATIONS__;
  var EXPAND_AUTHORS = __EXPAND_AUTHORS__;
  var PAGE_SIZE = 20;
  // Mirrors Scraper::AUTHOR_EXPANSION_BUDGET (includes/scraper.php) - a
  // large backlog of truncated publications trickles in over several runs
  // instead of firing dozens of extra requests from a single click.
  var AUTHOR_EXPANSION_BUDGET = 15;

  function notify(message, isError) {
    var el = document.getElementById('wp-scholar-bookmarklet-notice');
    if (!el) {
      el = document.createElement('div');
      el.id = 'wp-scholar-bookmarklet-notice';
      el.style.cssText = 'position:fixed;top:16px;right:16px;z-index:2147483647;max-width:360px;' +
        'padding:12px 16px;border-radius:6px;font:14px/1.4 -apple-system,Segoe UI,sans-serif;' +
        'box-shadow:0 2px 10px rgba(0,0,0,.25);';
      document.body.appendChild(el);
    }
    el.style.background = isError ? '#d63638' : '#1d2327';
    el.style.color = '#fff';
    el.textContent = message;
  }

  var profileId = new URLSearchParams(location.search).get('user');

  if (!profileId) {
    notify('Open your own Google Scholar profile page first, then click this bookmarklet.', true);
    return;
  }

  if (!document.getElementById('gsc_prf_in')) {
    notify('This page does not contain a public Google Scholar profile. Open the profile page and try again.', true);
    return;
  }

  function text(el) {
    return el ? el.textContent.trim() : '';
  }

  function extractProfile() {
    var interests = Array.prototype.map.call(
      document.querySelectorAll('#gsc_prf_int a'),
      function (a) {
        return { text: a.textContent.trim(), url: 'https://scholar.google.com' + a.getAttribute('href') };
      }
    );

    var cells = document.querySelectorAll('#gsc_rsb_st td.gsc_rsb_std');
    var citations = { total: 0, since_2019: 0, h_index: 0, h_index_2019: 0, i10_index: 0, i10_index_2019: 0 };
    if (cells.length >= 6) {
      citations.total = parseInt(cells[0].textContent, 10) || 0;
      citations.since_2019 = parseInt(cells[1].textContent, 10) || 0;
      citations.h_index = parseInt(cells[2].textContent, 10) || 0;
      citations.h_index_2019 = parseInt(cells[3].textContent, 10) || 0;
      citations.i10_index = parseInt(cells[4].textContent, 10) || 0;
      citations.i10_index_2019 = parseInt(cells[5].textContent, 10) || 0;
    }

    var coauthors = Array.prototype.map.call(
      document.querySelectorAll('.gsc_rsb_aa'),
      function (row) {
        var link = row.querySelector('a');
        if (!link) {
          return null;
        }
        var affiliation = row.querySelector('.gsc_rsb_a_ext');
        return {
          name: link.textContent.trim(),
          profile_url: 'https://scholar.google.com' + link.getAttribute('href'),
          title: affiliation ? affiliation.textContent.trim() : '',
          avatar: ''
        };
      }
    ).filter(Boolean);

    // Only the avatar's URL is captured here, never its bytes - fetching
    // the image itself from this origin would risk CORS. WordPress
    // downloads it server-side from this URL instead.
    var avatarImg = document.getElementById('gsc_prf_pup-img');

    return {
      profile_id: profileId,
      name: text(document.getElementById('gsc_prf_in')),
      affiliation: text(document.querySelector('.gsc_prf_il')),
      avatar_url: avatarImg ? avatarImg.src : '',
      interests: interests,
      citations: citations,
      coauthors: coauthors
    };
  }

  function extractPublications(doc) {
    return Array.prototype.map.call(
      doc.querySelectorAll('tr.gsc_a_tr'),
      function (row) {
        var titleLink = row.querySelector('a.gsc_a_at');
        if (!titleLink) {
          return null;
        }
        var grays = row.querySelectorAll('.gs_gray');
        var yearEl = row.querySelector('.gsc_a_h');
        var citeLink = row.querySelector('a.gsc_a_ac');
        var url = 'https://scholar.google.com' + titleLink.getAttribute('href');

        return {
          title: titleLink.textContent.trim(),
          link: url,
          google_scholar_url: url,
          authors: grays[0] ? grays[0].textContent.trim() : '',
          venue: grays[1] ? grays[1].textContent.trim() : '',
          year: yearEl ? yearEl.textContent.trim() : '',
          citations: citeLink ? (parseInt(citeLink.textContent, 10) || 0) : 0,
          citations_url: citeLink ? 'https://scholar.google.com' + citeLink.getAttribute('href') : '',
          citations_by_year_url: citeLink ? 'https://scholar.google.com' + citeLink.getAttribute('href') + '&view_op=view_citation_years' : ''
        };
      }
    ).filter(Boolean);
  }

  function fetchPage(start) {
    var url = 'https://scholar.google.com/citations?user=' + encodeURIComponent(profileId) +
      '&hl=en&cstart=' + start + '&pagesize=' + PAGE_SIZE;

    // Same origin as the page this bookmarklet runs on, so preserving the
    // current session avoids Scholar returning a different, limited page.
    return fetch(url, { credentials: 'same-origin' })
      .then(function (res) {
        if (!res.ok) {
          throw new Error('Google Scholar returned HTTP ' + res.status + ' while loading more publications.');
        }
        return res.text();
      })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        return extractPublications(doc);
      });
  }

  function isTruncatedAuthors(authors) {
    var trimmed = (authors || '').replace(/\s+$/, '');
    return trimmed.slice(-1) === '…' || trimmed.slice(-3) === '...';
  }

  function fetchFullAuthors(citationUrl) {
    if (!citationUrl) {
      return Promise.resolve(null);
    }

    return fetch(citationUrl, { credentials: 'same-origin' })
      .then(function (res) {
        return res.ok ? res.text() : null;
      })
      .then(function (html) {
        if (!html) {
          return null;
        }
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var fields = doc.querySelectorAll('.gsc_oci_field');
        for (var i = 0; i < fields.length; i++) {
          if (fields[i].textContent.trim() === 'Authors') {
            var value = fields[i].nextElementSibling;
            var text = value ? value.textContent.trim() : '';
            return text || null;
          }
        }
        return null;
      })
      .catch(function () {
        return null; // Best-effort: leave this publication truncated on failure.
      });
  }

  // Resolves full author lists for still-truncated publications, one
  // request at a time (never in parallel - polite to Scholar and keeps the
  // budget an exact request count). Already-expanded publications
  // (authors_full from a previous run's data, carried over server-side) are
  // skipped before this is ever called.
  function expandTruncatedAuthors(publications) {
    var budget = AUTHOR_EXPANSION_BUDGET;
    var candidates = publications.filter(function (pub) {
      return isTruncatedAuthors(pub.authors);
    });

    function next(index) {
      if (index >= candidates.length || budget <= 0) {
        return Promise.resolve();
      }
      var pub = candidates[index];
      budget--;
      return fetchFullAuthors(pub.google_scholar_url).then(function (fullAuthors) {
        if (fullAuthors) {
          pub.authors = fullAuthors;
          pub.authors_full = true;
        }
        return next(index + 1);
      });
    }

    return next(0);
  }

  function collectAllPublications() {
    // Always fetch from the first page with this bookmarklet's PAGE_SIZE.
    // The profile page may be configured to show a different number of rows,
    // which otherwise causes duplicates and gaps in the copied list.
    var all = [];

    function next(start) {
      if (all.length >= MAX_PUBLICATIONS) {
        return Promise.resolve();
      }
      return fetchPage(start).then(function (pubs) {
        if (pubs.length === 0) {
          return;
        }
        all = all.concat(pubs);
        if (pubs.length < PAGE_SIZE) {
          return;
        }
        return next(start + PAGE_SIZE);
      });
    }

    return next(0).then(function () {
      return all.slice(0, MAX_PUBLICATIONS);
    });
  }

  function copyWithExecCommand(value) {
    var textarea = document.createElement('textarea');
    textarea.value = value;
    textarea.setAttribute('readonly', '');
    textarea.style.cssText = 'position:fixed;left:-9999px;top:0;opacity:0;';
    document.body.appendChild(textarea);
    textarea.focus();
    textarea.select();

    var copied = false;
    try {
      copied = document.execCommand('copy');
    } catch (err) {
      copied = false;
    }

    document.body.removeChild(textarea);
    return copied;
  }

  function copyToClipboard(value) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      try {
        return navigator.clipboard.writeText(value).then(function () {
          return true;
        }, function () {
          return copyWithExecCommand(value);
        });
      } catch (err) {
        return copyWithExecCommand(value);
      }
    }

    return Promise.resolve(copyWithExecCommand(value));
  }

  function showCopyPanel(json, publicationCount) {
    var panel = document.getElementById('wp-scholar-bookmarklet-copy-panel');
    if (!panel) {
      panel = document.createElement('div');
      panel.id = 'wp-scholar-bookmarklet-copy-panel';
      panel.style.cssText = 'position:fixed;top:16px;right:16px;z-index:2147483647;width:min(480px,calc(100vw - 32px));' +
        'padding:16px;border-radius:6px;background:#fff;color:#1d2327;font:14px/1.4 -apple-system,Segoe UI,sans-serif;' +
        'box-shadow:0 2px 14px rgba(0,0,0,.35);';

      var message = document.createElement('p');
      message.id = 'wp-scholar-bookmarklet-copy-message';
      message.style.margin = '0 0 10px';
      panel.appendChild(message);

      var button = document.createElement('button');
      button.type = 'button';
      button.textContent = 'Copy data';
      button.style.cssText = 'margin:0 8px 10px 0;padding:7px 12px;border:0;border-radius:4px;background:#2271b1;color:#fff;cursor:pointer;';
      panel.appendChild(button);

      var textarea = document.createElement('textarea');
      textarea.id = 'wp-scholar-bookmarklet-copy-data';
      textarea.readOnly = true;
      textarea.rows = 7;
      textarea.style.cssText = 'display:block;width:100%;box-sizing:border-box;font:12px/1.4 monospace;';
      panel.appendChild(textarea);

      button.addEventListener('click', function () {
        copyToClipboard(textarea.value).then(function (copied) {
          if (copied) {
            panel.parentNode.removeChild(panel);
            notify('Copied ' + publicationCount + ' publications to your clipboard. Switch to your wp-admin tab and paste into the Import box.');
            return;
          }

          textarea.focus();
          textarea.select();
          message.textContent = 'Automatic copying is blocked. Press Ctrl/Cmd+C to copy the selected data.';
        });
      });

      document.body.appendChild(panel);
    }

    panel.querySelector('#wp-scholar-bookmarklet-copy-message').textContent =
      'Your browser needs one final confirmation before copying ' + publicationCount + ' publications.';
    var textarea = panel.querySelector('#wp-scholar-bookmarklet-copy-data');
    textarea.value = json;
    textarea.focus();
    textarea.select();
  }

  notify('Collecting your Scholar profile data...');

  collectAllPublications().then(function (publications) {
    var data = extractProfile();
    data.publications = publications;

    if (!EXPAND_AUTHORS) {
      return data;
    }

    notify('Collecting your Scholar profile data... (fetching full author lists)');
    return expandTruncatedAuthors(publications).then(function () {
      return data;
    });
  }).then(function (data) {
    var publications = data.publications;
    var json = JSON.stringify(data);

    copyToClipboard(json).then(function (copied) {
      if (copied) {
        notify('Copied ' + publications.length + ' publications to your clipboard. Switch to your wp-admin tab and paste into the Import box.');
        return;
      }

      showCopyPanel(json, publications.length);
      notify('Click “Copy data” in the panel to finish copying.', true);
    });
  }).catch(function (err) {
    notify('Something went wrong collecting your profile data: ' + err.message, true);
  });
})();
