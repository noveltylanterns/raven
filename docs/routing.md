# Routing Table

The Routing Table is a read-only inventory of the public URLs Raven currently
knows how to serve. Use it to review canonical paths, inspect redirects, and
spot conflicting routes before publishing changes.

## Open the Routing Table

In the panel, open **Routing** from the navigation. Raven requires an
authenticated panel session and the routing-table view permission.

The summary cards show counts for pages, channels, redirects, and conflicts.
The table itself can include public content, feeds, taxonomy routes, profile
and group routes, and other configured public destinations.

## Search and filter

Use the search box to match a route's title, URL, type, or status. The type
filters narrow the table to the route families you want to inspect, while the
status selector filters by the route's current state.

Enable **Conflicts only** to focus on rows Raven identified as overlapping or
otherwise requiring attention. The result count updates as filters are
changed.

Click a column heading to sort the table. URI sorting is based on the complete
stored channel path, so child-channel routes sort alongside their full parent
paths rather than appearing under an unrelated child slug.

## Understand a row

- **URI** is the public path Raven reports for the route. Use the copy button
  beside it to copy the path.
- **Title** identifies the page, channel, or other route source. When an edit
  screen is available, the title links to it.
- **Type** identifies the route family, such as page, channel, or redirect.
- **Status** reports the route's current routing state and may include conflict
  information.

Public URLs open in a new browser tab so you can verify the resolved result
without losing your place in the panel.

## Export

Select **Export CSV** to download the currently available routing inventory.
The export includes the route type, title, public URL, target URL, status,
notes, and conflict details for use in audits or deployments.

## Troubleshooting

If a route is missing, check that its content is published and that the
relevant feature or public prefix is enabled in configuration. If a route is
marked as conflicting, inspect the listed URI and target, then adjust the
content path, channel hierarchy, redirect, or configured prefix as needed.

For content-specific behavior, see [Pages](./pages.md),
[Channels](./channels.md), and [Redirects](./redirects.md). For the router's
implementation contracts, see the [Router Developer Reference](./appendix/router.md).
