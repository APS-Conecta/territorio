## 2026-10-06 - Geolocation accuracy edge cases

When handling geolocation accuracy readings, a zero accuracy value (`0`) represents missing or untrustworthy accuracy data rather than 0m exactness. Formatting `0` metres as `±0 m` produces a misleading accuracy claim on screen.
