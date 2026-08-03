# Google Scholar Profile Display

A WordPress plugin that allows you to display your Google Scholar profile information on your website using a simple shortcode.

## Description

This plugin fetches and displays information from Google Scholar profiles, including:

- Profile avatar
- Basic information (name, affiliation)
- Publications list with pagination
- Citation metrics
- Interactive sorting and filtering

The data can be automatically updated on a schedule, or imported manually from
your browser when Google Scholar blocks server-side requests.

## Installation

1. Upload the plugin ZIP through **Plugins → Add New → Upload Plugin**, or place it in `/wp-content/plugins/google-scholar-wp/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to Settings > Scholar Profile to configure the plugin

## Configuration

### Required Settings

- **Profile ID**: Your Google Scholar profile ID (found in your profile URL)
  - Example: If your profile URL is `https://scholar.google.com/citations?user=XXXYYY`, then XXXYYY is your profile ID

### Optional Settings

- **Display Options**:
  - Show/hide avatar
  - Show/hide profile information
  - Show/hide publications list
  - Show/hide co-authors
- **Update Frequency**:
  - Daily
  - Weekly (default)
  - Monthly
  - Yearly
- **Max Publications**: Control how many publications to fetch (50, 100, 200, 500)
- **Update Method**:
  - **Server** (default): WordPress retrieves the profile on the configured schedule.
  - **Browser**: You import the data from a browser where you can open the Scholar profile. This is useful when Google Scholar blocks requests from your server.

## Usage

### Basic Usage

Add the shortcode to any post or page:

```
[scholar_profile]
```

### Pagination Options

Control how many publications are displayed per page:

```
[scholar_profile per_page="10"]
[scholar_profile per_page="20"]
[scholar_profile per_page="50"]
```

**Default**: 20 publications per page
**Range**: 1-100 publications per page

### Sorting Options

You can sort publications by specifying sorting parameters:

```
[scholar_profile sort_by="year" sort_order="desc"]
[scholar_profile sort_by="citations" sort_order="desc"]
[scholar_profile sort_by="title" sort_order="asc"]
```

**Available sort_by values:**

- `year` - Sort by publication year
- `citations` - Sort by citation count
- `title` - Sort alphabetically by title

**Available sort_order values:**

- `desc` - Descending order (default)
- `asc` - Ascending order

### Combined Parameters

You can combine pagination and sorting options:

```
[scholar_profile per_page="15" sort_by="citations" sort_order="desc"]
```

### Interactive Features

**Sorting:**

- Click any column header to sort by that field
- Click again to reverse the sort order
- Visual indicators (arrows) show the current sort direction
- Fully accessible with keyboard navigation (Tab + Enter/Space)

**Pagination:**

- Navigate through pages using Previous/Next buttons
- Jump to specific pages using page numbers
- URL parameters are updated for bookmarkable pages
- Responsive design adapts to mobile devices

**URL Parameters:**

- `scholar_page` - Current page number
- `scholar_sort_by` - Current sort field
- `scholar_sort_order` - Current sort order

### Server Updates

With **Update Method** set to **Server**, you can refresh immediately from
**Settings → Scholar Profile → Refresh Profile Data**. WordPress also refreshes
the data on the configured schedule.

### Browser-Assisted Import and Bookmarklet

Use Browser mode when your server cannot reliably access Google Scholar.

1. Go to **Settings → Scholar Profile**, select **Browser** as the update method, and save the settings.
2. Enter and save your Google Scholar Profile ID if you have not already done so.
3. Drag **Import Scholar Data** to your browser's bookmarks bar. Do not click the button on the WordPress settings page.
4. Open your public Google Scholar profile and click the saved bookmarklet.
5. The bookmarklet collects your profile details and publications, then copies the import data to the clipboard. Return to WordPress, paste it into the import box, and select **Replace profile data**.

The bookmarklet starts at the first publication page and follows Google
Scholar's pagination (`cstart=0`, `20`, `40`, and so on) until it reaches the
configured **Max Publications** value or the final page. It uses the browser's
existing Scholar session, which helps when an authenticated browser can access
the profile but your server cannot.

Some browsers require a fresh user action before granting clipboard access. In
that case, the bookmarklet shows a small panel: click **Copy data**, then paste
the JSON into the WordPress import box. If copying is still blocked, the data
remains selected in that panel so you can press Ctrl/Cmd+C yourself.

After updating the plugin, delete the previous bookmarklet and drag the button
to the bookmarks bar again. A bookmark stores its JavaScript at the time it is
created and does not update itself.

#### Fallback: import page HTML

If you cannot use the bookmarklet, open the profile page, select all, copy, and
paste it into the import box. Use **Replace profile data** for the main profile
page. For later Scholar pages such as `&cstart=20` or `&cstart=40`, paste the
page and choose **Add publications from another page** to preserve the existing
profile data.

## Advanced Features

### Performance Considerations

- **Pagination**: Large publication lists are automatically paginated for better performance
- **Client-side Sorting**: Sorting within the current page happens instantly
- **Server-side Sorting**: Full dataset sorting requires a page refresh
- **Responsive Design**: Optimized for desktop, tablet, and mobile viewing

### Accessibility

- Full keyboard navigation support
- ARIA labels for screen readers
- Semantic HTML structure
- High contrast design
- Focus indicators for interactive elements

## Styling

The plugin includes comprehensive CSS styles that can be customized through your theme's stylesheet. Main CSS classes:

```css
.scholar-profile {}              /* Main container */
.scholar-header {}               /* Profile header section */
.scholar-avatar {}               /* Profile image container */
.scholar-basic-info {}           /* Profile information section */
.scholar-publications {}         /* Publications section */
.scholar-publications-table {}   /* Publications table */
.scholar-pagination {}           /* Pagination navigation */
.scholar-pagination-wrapper {}   /* Pagination container */
.scholar-pagination-number {}    /* Individual page numbers */
.scholar-pagination-btn {}       /* Previous/Next buttons */
.scholar-metrics-box {}          /* Citation metrics */
.scholar-coauthors {}            /* Co-authors section */
```

### CSS Custom Properties

The plugin uses CSS custom properties for easy theming:

```css
:root {
  --scholar-primary-color: #1a73e8;
  --scholar-primary-hover: #1557b0;
  --scholar-border-color: #dadce0;
  --scholar-text-color: #202124;
  --scholar-text-secondary: #666;
  --scholar-background-light: #f8f9fa;
}
```

## Performance Tips

1. **Pagination**: Use smaller `per_page` values (10-20) for better initial load times
2. **Update Frequency**: Use weekly or monthly updates for large profiles
3. **Publication Limits**: Consider limiting max publications in settings for very large profiles
4. **Caching**:
