## Release Notes vs CHANGELOG

`CHANGELOG.md` is **auto-populated by CI** on release. Do not hand-edit it.

When you need to document a user-facing change for a release, write it to `RELEASE_NOTES_<version>.md` at the repo root (already gitignored via the `RELEASE_NOTES*.md` pattern). The CI release job picks it up and promotes it into `CHANGELOG.md` as part of the tag flow.

If you find yourself editing `CHANGELOG.md` directly, stop — it will be overwritten.
