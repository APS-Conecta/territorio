## 2026-10-08 - Unneeded module export on internal function

When removing unneeded exports, verify with `grep` across all source and test files that no external caller imports the symbol before removing `export`. Functions only called internally in their declaring file (such as `archiveReachable` in `src/basemap.js`) should not be exported.
