# Public Guest Audit Sweep

Date: 2026-03-25

Scope:
- Real internet-edge testing over `https://dev.thenocturnalnetwork.com` behind the environment's Basic Auth gate.
- Public guest routes only.
- Optional public routes were enabled during the sweep: categories, tags, groups, and profiles.
- This file intentionally excludes findings that were already retested as fixed.

## 1. Unknown two-segment public paths can still trigger a guest-visible `500`

Severity: Medium

Summary:
- Arbitrary unknown two-segment guest paths such as `/alpha/beta` and `/etc/passwd` still reach a code path that throws an uncaught exception.
- The client response body did not leak internals, but the route still fails with `HTTP 500` instead of cleanly returning `404`.
- This is broader than a traversal-only bug. It affects normal `/{channel}/{slug}` requests when the channel slug does not exist.

Live repro:
- `GET /alpha/beta` -> `HTTP 500`
- `GET /etc/passwd` -> `HTTP 500`
- Additional sampled unknown two-segment paths also returned `500`: `/rfzbogus123/page`, `/news/archive`, `/aaaa/bbbb`, `/x/y`

Server-side evidence:
- PHP log recorded: `RuntimeException: Selected channel does not exist.`
- Logged stack path:
  - [ChannelContextService.php#L81](/home/dev/app/private/lib/Routing/ChannelContextService.php#L81)
  - [RedirectRepository.php#L419](/home/dev/app/private/sys/Repository/RedirectRepository.php#L419)
  - [PublicController.php#L712](/home/dev/app/private/sys/Controller/PublicController.php#L712)
  - [PublicController.php#L215](/home/dev/app/private/sys/Controller/PublicController.php#L215)

Root cause shape:
- The public two-segment route enters `PublicController::page($slug, $channel)`.
- When the channel slug does not resolve, the fallback redirect lookup path still attempts channel-id resolution and throws instead of failing closed.

Patch target:
- Unknown channel slugs in the two-segment public route must short-circuit to `404`.
- Redirect lookup for `/{channel}/{slug}` should never throw on a missing channel.
- Catch or avoid the `ChannelContextService::resolveChannelIdBySlug()` exception in this guest path.

## 2. Public profile and group pages still expose email-like identifiers to guests

Severity: Medium

Summary:
- Guest-accessible public pages still render `@username` values.
- In `email` login mode, Raven still defaults `username` to the email address when the submitted username is blank.
- The recent route change only moved direct profile lookup from `/{profile_prefix}/{email}` to `/{profile_prefix}/{id}`. It did not remove the guest-facing `username` output.
- Result: both numeric profile pages and group member lists can expose email-like identifiers publicly.

Root cause:
- Username fallback to email in [UserPersistenceService.php#L55](/home/dev/app/private/lib/Auth/UserPersistenceService.php#L55)
- Email-login installs now resolve public profiles by numeric user id in [PublicController.php#L2310](/home/dev/app/private/sys/Controller/PublicController.php#L2310)
- Guest profile template still renders username directly in [full.php#L22](/home/dev/app/public/theme/raven/tpl/profile/full.php#L22)
- Guest group template still renders username directly in [list.php#L48](/home/dev/app/public/theme/raven/tpl/group/list.php#L48)
- Core fallback templates do the same in [full.php#L22](/home/dev/app/private/tpl/profile/full.php#L22) and [list.php#L48](/home/dev/app/private/tpl/group/list.php#L48)

Live repro:
- Create or use a user whose `username` is email-shaped.
- Visit `/{profile_prefix}/{user_id}` as a guest.
- Observed result: `HTTP 200` and the profile page shows `@email@example.test` style output.
- Visit `/{group_prefix}/{group_slug}` as a guest for a group containing that user.
- Observed result: `HTTP 200` and the member list shows the same email-style identifier.
- Control observation: `/{profile_prefix}/{urlencoded_email}` now returns `404`, which confirms the route format changed, not the underlying exposure.

Why this still matters:
- Numeric profile routes are easy to enumerate.
- The patch reduced one direct route form, but not the privacy leak itself.
- The group page leak remains live, and the numeric profile route adds another confirmed guest-visible surface.

Patch target:
- Do not default `username` to the email address in `email` login mode, or
- Suppress public rendering of usernames that are email-shaped, or
- Remove guest-facing username output from public profile and group templates when the install is configured for email login.
