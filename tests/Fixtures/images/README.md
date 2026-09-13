# Image fixtures

Byte images for the image-processor contract and canonical-delivery tests. Each carries a searchable
`LEAK` or date sentinel, so a byte that survives a re-encode fails an assertion. GD wrote
a clean baseline image, then a throwaway generator spliced hand-built EXIF / XMP / comment segments
(a real GPS IFD plus Orientation) into the container:

| file | contents |
|---|---|
| `jpeg-gps-orientation.jpg` | APP1 EXIF with a GPS IFD (`GPSDateStamp` "2021:07:04") + Orientation 6; base image 12x6 landscape |
| `jpeg-postsos-meta.jpg` | progressive JPEG with a COM + EXIF (GPS) placed after the last SOS |
| `jpeg-app2-mixed.jpg` | a non-ICC APP2 (`MPFDROPME`) and a real `ICC_PROFILE` APP2 (`ICCKEEPME`) |
| `jpeg-copyright.jpg` | APP1 EXIF whose only tag is Copyright (`COPYRIGHT-LEAK-2021`) + a COM segment (`COMMENT-LEAK`); base image 12x6. Backends that strip metadata tend to keep copyright by default |
| `png-meta.png` | `eXIf` (GPS) + `tEXt` (`png-text-LEAK`) after IHDR |
| `png-badcrc.png` | `png-meta.png` with one chunk's CRC flipped (GD decodes it regardless) |
| `webp-vp8x-meta.webp` | VP8X (flags EXIF + XMP) + GD's VP8 image + an odd-length EXIF whose only tag is Copyright (`exif-LEAK 2021:07:04`) + `XMP ` (`xmp-LEAK`); a valid container, so libwebp reads it too |
| `apng-2frames.png` | a 4x4 APNG of two frames (`acTL` + `fcTL`/`fdAT`), red then blue; both processors read the first frame only |
| `tiny.gif` | plain GIF, no metadata |
| `jpeg-truncated.jpg` | a JPEG cut short. Not a processor-refusal case: libjpeg recovers and GD decodes it to a partial picture |
