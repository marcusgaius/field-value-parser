# Release Notes for Field Value Parser

## 1.2.0 - 2026-10-23

### Changed
- `Services\Sites::isValueShared()` also takes the element a value would be written into, in place of a site ID. A separate element shares none of its values, whatever the node's translation method, as it has content rows of its own. Passing a site ID behaves as before.

## 1.1.0 - 2026-10-08

### Added
- `Traits\Editions`, the Lite and Pro editions of plugins built on Field Value Parser.

## 1.0.1 - 2026-09-29

### Fixed
- `craft help` no longer fails in projects using plugins built on Field Value Parser.

## 1.0.0 - 2026-09-25
- Initial release
