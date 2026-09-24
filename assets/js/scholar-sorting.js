/**
 * Google Scholar Profile
 * Handles interactive sorting and pagination for publications table
 */

(function() {
  'use strict';

  let currentSort = null;
  let currentOrder = 'desc';
  let allPublications = [];
  let currentPaginationData = null;

  /**
   * Initialize sorting and pagination functionality when DOM is ready
   */
  function initSorting() {
      const tables = document.querySelectorAll('.scholar-publications-table[data-sortable="true"]');
      
      tables.forEach(table => {
          // Store all publications data for client-side sorting
          storePublicationsData(table);
          
          // Initialize sort headers
          const headers = table.querySelectorAll('th.sortable');
          headers.forEach(header => {
              header.addEventListener('click', handleSort);
              header.addEventListener('keydown', handleKeyDown);
          });
      });

      // Initialize pagination click handlers
      initPaginationHandlers();
  }

  /**
   * Store publications data for client-side sorting and pagination
   */
  function storePublicationsData(table) {
      const profile = table.closest('.scholar-profile');
      if (!profile) return;

      // Get pagination data from the profile container
      const paginationDataAttr = profile.getAttribute('data-pagination');
      if (paginationDataAttr) {
          try {
              currentPaginationData = JSON.parse(paginationDataAttr);
          } catch (e) {
              console.error('Failed to parse pagination data:', e);
              return;
          }
      }

      // Store current page publications
      const rows = table.querySelectorAll('.publications-tbody tr');
      const currentPagePublications = [];
      
      rows.forEach(row => {
          const titleEl = row.querySelector('.scholar-publication-title');
          const yearEl = row.querySelector('.publication-year');
          const citationsEl = row.querySelector('.publication-citations');
          
          if (titleEl && yearEl && citationsEl) {
              currentPagePublications.push({
                  title: titleEl.textContent.trim(),
                  year: parseInt(yearEl.getAttribute('data-year') || '0'),
                  citations: parseInt(citationsEl.getAttribute('data-citations') || '0'),
                  element: row.cloneNode(true) // Store the full row element
              });
          }
      });

      // For client-side sorting, we'll work with current page data
      // In a full implementation, you'd want to fetch all data via AJAX
      allPublications = currentPagePublications;
  }

  /**
   * Initialize pagination click handlers
   */
  function initPaginationHandlers() {
      const paginationLinks = document.querySelectorAll('.scholar-pagination a');
      
      paginationLinks.forEach(link => {
          link.addEventListener('click', handlePaginationClick);
      });
  }

  /**
   * Handle pagination link clicks
   */
  function handlePaginationClick(event) {
      // Let the default behavior handle navigation
      // but add loading indicator
      const link = event.currentTarget;
      const pagination = link.closest('.scholar-pagination');
      
      if (pagination) {
          pagination.classList.add('loading');
      }
  }

  /**
   * Handle keyboard navigation for accessibility
   */
  function handleKeyDown(event) {
      if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          handleSort.call(this, event);
      }
  }

  /**
   * Handle sort button clicks
   */
  function handleSort(event) {
      const header = event.currentTarget;
      const table = header.closest('table');
      const sortBy = header.getAttribute('data-sort');
      
      // Determine sort order
      if (currentSort === sortBy) {
          currentOrder = currentOrder === 'desc' ? 'asc' : 'desc';
      } else {
          currentOrder = 'desc'; // Default to descending for new sorts
          currentSort = sortBy;
      }

      // Update visual indicators
      updateSortIndicators(table, header, currentOrder);
      
      // For client-side sorting (current page only)
      sortCurrentPage(table, sortBy, currentOrder);
      
      // Update accessibility
      updateAriaLabel(header, sortBy, currentOrder);

      // Reset to page 1 when sorting changes (by updating URL)
      updateURLWithSort(sortBy, currentOrder);
  }

  /**
   * Update URL with sort parameters and reset to page 1
   */
  function updateURLWithSort(sortBy, sortOrder) {
      const url = new URL(window.location);
      
      // Update sort parameters
      const params = new URLSearchParams(url.search);
      params.set('scholar_sort_by', sortBy);
      params.set('scholar_sort_order', sortOrder);
      params.delete('scholar_page'); // Reset to page 1
      
      // Update URL without page reload
      const newURL = url.pathname + '?' + params.toString();
      window.history.replaceState({}, '', newURL);
      
      // For full implementation, you'd reload the page or fetch new data via AJAX
      // For now, we'll show a message that a page reload is needed for full sorting
      showSortMessage();
  }

  /**
   * Show message about sorting across all pages
   */
  function showSortMessage() {
      const table = document.querySelector('.scholar-publications-table');
      if (!table || !currentPaginationData || currentPaginationData.total_pages <= 1) {
          return;
      }

      // Create and show a temporary message
      const message = document.createElement('div');
      message.className = 'scholar-sort-message';
      message.innerHTML = '📄 Sorting applied to current page. <a href="' + window.location.href + '">Refresh page</a> to sort all publications.';
      message.style.cssText = `
          background: #e7f3ff;
          border: 1px solid #2271b1;
          border-radius: 4px;
          padding: 8px 12px;
          margin: 10px 0;
          font-size: 14px;
          color: #2271b1;
      `;

      // Insert after publications header
      const publicationsHeader = document.querySelector('.scholar-publications-header');
      if (publicationsHeader) {
          publicationsHeader.parentNode.insertBefore(message, publicationsHeader.nextSibling);
          
          // Auto-remove after 5 seconds
          setTimeout(() => {
              if (message.parentNode) {
                  message.parentNode.removeChild(message);
              }
          }, 5000);
      }
  }

  /**
   * Update visual sort indicators
   */
  function updateSortIndicators(table, activeHeader, order) {
      // Clear all existing indicators
      const allHeaders = table.querySelectorAll('th.sortable');
      allHeaders.forEach(header => {
          header.classList.remove('sorted-asc', 'sorted-desc');
          const arrow = header.querySelector('.sort-arrow');
          if (arrow) {
              arrow.textContent = '';
          }
      });

      // Set indicator for active header
      activeHeader.classList.add(order === 'asc' ? 'sorted-asc' : 'sorted-desc');
      const arrow = activeHeader.querySelector('.sort-arrow');
      if (arrow) {
          arrow.textContent = order === 'asc' ? ' ↑' : ' ↓';
      }
  }

  /**
   * Update ARIA label for accessibility
   */
  function updateAriaLabel(header, sortBy, currentOrder) {
      const nextOrder = currentOrder === 'desc' ? 'ascending' : 'descending';
      const sortLabel = getSortLabel(sortBy);
      header.setAttribute('aria-label', `Sort by ${sortLabel} (${nextOrder})`);
  }

  /**
   * Get human-readable sort label
   */
  function getSortLabel(sortBy) {
      const labels = {
          'title': 'title',
          'year': 'year',
          'citations': 'citations'
      };
      return labels[sortBy] || sortBy;
  }

  /**
   * Sort the current page publications
   * Matches Google Scholar behavior with secondary sorting:
   * - Sort by year → secondary sort by citations
   * - Sort by citations → secondary sort by year
   */
  function sortCurrentPage(table, sortBy, order) {
      const tbody = table.querySelector('.publications-tbody');
      if (!tbody || allPublications.length === 0) return;

      // Sort the stored publications data
      const sortedPublications = [...allPublications].sort((a, b) => {
          let valueA = a[sortBy];
          let valueB = b[sortBy];

          if (sortBy === 'title') {
              valueA = valueA.toLowerCase();
              valueB = valueB.toLowerCase();
          }

          // Compare primary field
          let comparison = 0;
          if (valueA !== valueB) {
              if (order === 'asc') {
                  comparison = valueA < valueB ? -1 : 1;
              } else {
                  comparison = valueA > valueB ? -1 : 1;
              }
          }

          // If primary fields are equal, apply secondary sort (matching Google Scholar)
          if (comparison === 0) {
              if (sortBy === 'year') {
                  // When sorting by year, secondary sort by citations (descending)
                  comparison = b.citations - a.citations;
              } else if (sortBy === 'citations') {
                  // When sorting by citations, secondary sort by year (descending)
                  comparison = b.year - a.year;
              }
          }

          return comparison;
      });

      // Clear current tbody
      tbody.innerHTML = '';

      // Add sorted rows
      sortedPublications.forEach(pub => {
          tbody.appendChild(pub.element.cloneNode(true));
      });

      // Add visual feedback
      addSortFeedback(table);
  }

  /**
   * Add visual feedback after sorting
   */
  function addSortFeedback(table) {
      table.classList.add('sorting-updated');
      setTimeout(() => {
          table.classList.remove('sorting-updated');
      }, 300);
  }

  /**
   * Initialize URL-based sorting on page load
   */
  function initURLBasedSorting() {
      const params = new URLSearchParams(window.location.search);
      const sortBy = params.get('scholar_sort_by');
      const sortOrder = params.get('scholar_sort_order') || 'desc';

      if (sortBy && ['title', 'year', 'citations'].includes(sortBy)) {
          const table = document.querySelector('.scholar-publications-table');
          const header = table?.querySelector(`th[data-sort="${sortBy}"]`);
          
          if (header && table) {
              currentSort = sortBy;
              currentOrder = sortOrder;
              updateSortIndicators(table, header, sortOrder);
              updateAriaLabel(header, sortBy, sortOrder);
          }
      }
  }

  /**
   * Add pagination loading states
   */
  function initPaginationLoadingStates() {
      const paginationWrapper = document.querySelector('.scholar-pagination-wrapper');
      if (!paginationWrapper) return;

      // Add CSS for loading state
      const style = document.createElement('style');
      style.textContent = `
          .scholar-pagination.loading {
              opacity: 0.6;
              pointer-events: none;
          }
          .scholar-pagination.loading::after {
              content: "";
              position: absolute;
              top: 50%;
              left: 50%;
              width: 20px;
              height: 20px;
              margin: -10px 0 0 -10px;
              border: 2px solid #ccc;
              border-top-color: #2271b1;
              border-radius: 50%;
              animation: spin 1s linear infinite;
          }
          @keyframes spin {
              to { transform: rotate(360deg); }
          }
      `;
      document.head.appendChild(style);
  }

  /**
   * Initialize when DOM is ready
   */
  function init() {
      initSorting();
      initURLBasedSorting();
      initPaginationLoadingStates();
  }

  if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', init);
  } else {
      init();
  }

})();