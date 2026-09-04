# Image fixtures

Byte images for the metadata-strip tests. Each carries a searchable `LEAK` or date sentinel, so a
byte that survives a strip fails an assertion. GD wrote a clean baseline image, then a throwaway
generator spliced hand-built EXIF / XMP / comment segments (a real GPS IFD plus Orientation) into
the container:

| file | contents |
|---|---|
| `jpeg-gps-orientation.jpg` | APP1 EXIF with a GPS IFD (`GPSDateStamp` "2021:07:04") + Orientation 6; base image 12x6 landscape |
| `jpeg-postsos-meta.jpg` | progressive JPEG with a COM + EXIF (GPS) placed after the last SOS |
| `jpeg-app2-mixed.jpg` | a non-ICC APP2 (`MPFDROPME`) and a real `ICC_PROFILE` APP2 (`ICCKEEPME`) |
| `png-meta.png` | `eXIf` (GPS) + `tEXt` (`png-text-LEAK`) after IHDR |
| `png-badcrc.png` | `png-meta.png` with one chunk's CRC flipped |
| `webp-vp8x-meta.webp` | VP8X (flags ICC + reserved + EXIF + XMP) + image + odd-length EXIF + `XMP ` |
| `tiny.gif` | plain GIF, no metadata |
| `jpeg-truncated.jpg` | a JPEG cut short, so the strip fails closed |
