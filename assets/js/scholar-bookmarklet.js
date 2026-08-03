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
 * __MAX_PUBLICATIONS__ is replaced with the plugin's configured
 * "Max Publications" setting when this file is turned into the
 * javascript: bookmarklet link on the settings page.
 */
(function () {
  var MAX_PUBLICATIONS = __MAX_PUBLICATIONS__;
  var PAGE_SIZE = 20;

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

    // Same origin as the page this bookmarklet runs on - no CORS involved.
    // credentials 'omit' keeps this to the plain public profile view.
    return fetch(url, { credentials: 'omit' })
      .then(function (res) { return res.text(); })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        return extractPublications(doc);
      });
  }

  function collectAllPublications() {
    var all = extractPublications(document);

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

    if (all.length < PAGE_SIZE) {
      return Promise.resolve(all.slice(0, MAX_PUBLICATIONS));
    }

    return next(PAGE_SIZE).then(function () {
      return all.slice(0, MAX_PUBLICATIONS);
    });
  }

  notify('Collecting your Scholar profile data...');

  collectAllPublications().then(function (publications) {
    var data = extractProfile();
    data.publications = publications;

    var json = JSON.stringify(data);

    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(json).then(function () {
        notify('Copied ' + publications.length + ' publications to your clipboard. Switch to your wp-admin tab and paste into the Import box.');
      }, function () {
        window.__scholarImportData = json;
        notify('Could not copy automatically - open the browser console and copy window.__scholarImportData manually.', true);
      });
    } else {
      window.__scholarImportData = json;
      notify('Clipboard access is unavailable - copy window.__scholarImportData from the console.', true);
    }
  }).catch(function (err) {
    notify('Something went wrong collecting your profile data: ' + err.message, true);
  });
})();
