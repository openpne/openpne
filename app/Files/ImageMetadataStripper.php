<?php

namespace App\Files;

/**
 * Removes metadata (EXIF/GPS, XMP, comments) from an uploaded image without touching the encoded
 * image data — no re-encode, so no quality loss. Works at the container level: it walks the byte
 * structure and rewrites it keeping only the segments an image needs to render faithfully.
 *
 * Deliberately kept:
 *   - JPEG EXIF Orientation (re-emitted as a minimal APP1) — display and thumbnail rotation read it.
 *   - JPEG ICC (APP2) / Adobe (APP14) and PNG iCCP/gAMA — colour fidelity.
 *
 * Fails closed: a structural parse error throws {@see ImageMetadataStripException} rather than
 * returning the original (unstripped) bytes.
 */
class ImageMetadataStripper
{
    /**
     * Return $bytes with metadata removed. $mime selects the container parser; a type this class
     * does not strip (e.g. image/gif) is returned unchanged. The caller gates which MIME types
     * reach here (FileUploader), so an unexpected type is a no-op, never a re-interpretation.
     */
    public function strip(string $bytes, string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => $this->stripJpeg($bytes),
            'image/png' => $this->stripPng($bytes),
            'image/webp' => $this->stripWebp($bytes),
            default => $bytes,
        };
    }

    /**
     * Allow-list segment walk from SOI to EOI. Structural markers are kept verbatim; among the APPn
     * segments only JFIF/JFXX (APP0), ICC (APP2) and Adobe (APP14) survive, every other APPn and COM
     * is dropped. The EXIF APP1 is dropped but its Orientation is preserved by re-emitting a minimal
     * APP1 right after SOI. Progressive/multi-scan JPEGs place markers between scans, so scan data is
     * copied verbatim only up to the next real marker, which then re-enters the allow-list.
     */
    private function stripJpeg(string $bytes): string
    {
        $length = strlen($bytes);
        if ($length < 2 || substr($bytes, 0, 2) !== "\xFF\xD8") {
            throw new ImageMetadataStripException('JPEG: missing SOI marker.');
        }

        $orientation = 1;
        $body = '';
        $i = 2;

        while (true) {
            if ($i >= $length || $bytes[$i] !== "\xFF") {
                throw new ImageMetadataStripException('JPEG: expected a marker.');
            }
            // Skip any 0xFF fill bytes preceding the marker code.
            do {
                $i++;
            } while ($i < $length && $bytes[$i] === "\xFF");
            if ($i >= $length) {
                throw new ImageMetadataStripException('JPEG: truncated marker.');
            }
            $marker = ord($bytes[$i]);
            $i++;

            if ($marker === 0xD9) { // EOI — assemble with orientation injected after SOI.
                return "\xFF\xD8".$this->orientationApp1($orientation).$body."\xFF\xD9";
            }

            // Standalone markers (TEM, RSTn) carry no length.
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $body .= "\xFF".chr($marker);

                continue;
            }

            if ($i + 2 > $length) {
                throw new ImageMetadataStripException('JPEG: truncated segment length.');
            }
            $segmentLength = (ord($bytes[$i]) << 8) | ord($bytes[$i + 1]);
            if ($segmentLength < 2 || $i + $segmentLength > $length) {
                throw new ImageMetadataStripException('JPEG: invalid segment length.');
            }
            $payload = substr($bytes, $i + 2, $segmentLength - 2);
            $segment = "\xFF".chr($marker).substr($bytes, $i, $segmentLength);
            $i += $segmentLength;

            if ($marker === 0xE1 && str_starts_with($payload, "Exif\x00\x00")) {
                // Drop EXIF, but carry its Orientation forward.
                $found = $this->orientationFromTiff(substr($payload, 6));
                if ($found !== null) {
                    $orientation = $found;
                }
            } elseif ($this->keepJpegSegment($marker, $payload)) {
                $body .= $segment;
            }

            if ($marker === 0xDA) { // SOS — copy the entropy-coded scan up to the next marker.
                [$scan, $i] = $this->copyJpegScan($bytes, $i, $length);
                $body .= $scan;
            }
        }
    }

    /** Whether a JPEG segment survives the allow-list. Non-APP/COM markers are structural = kept. */
    private function keepJpegSegment(int $marker, string $payload): bool
    {
        if ($marker >= 0xE0 && $marker <= 0xEF) { // APPn
            return match ($marker) {
                0xE0 => str_starts_with($payload, "JFIF\x00") || str_starts_with($payload, "JFXX\x00"),
                0xE2 => str_starts_with($payload, "ICC_PROFILE\x00"),
                0xEE => str_starts_with($payload, 'Adobe'),
                default => false,
            };
        }

        return $marker !== 0xFE; // drop COM, keep everything structural
    }

    /**
     * Copy entropy-coded scan data starting at $i up to (but excluding) the next real marker. A
     * 0xFF is part of the scan when followed by 0x00 (byte stuffing) or an RSTn marker; any other
     * 0xFF-xx pair is the next segment boundary.
     *
     * @return array{0: string, 1: int} the scan bytes and the index of the next marker's 0xFF
     */
    private function copyJpegScan(string $bytes, int $i, int $length): array
    {
        $start = $i;
        while ($i < $length) {
            if ($bytes[$i] !== "\xFF") {
                $i++;

                continue;
            }
            if ($i + 1 >= $length) {
                $i++;

                break;
            }
            $next = ord($bytes[$i + 1]);
            if ($next === 0x00 || ($next >= 0xD0 && $next <= 0xD7)) {
                $i += 2; // stuffed byte or restart marker: still scan data

                continue;
            }

            break; // real marker: stop, leave $i on the 0xFF
        }

        return [substr($bytes, $start, $i - $start), $i];
    }

    /**
     * A minimal EXIF APP1 segment carrying only Orientation (little-endian TIFF, one IFD0 entry).
     * Empty string when the orientation is normal/unknown, so an unrotated image gains no metadata.
     */
    private function orientationApp1(int $orientation): string
    {
        if ($orientation < 2 || $orientation > 8) {
            return '';
        }

        $tiff = "II\x2A\x00\x08\x00\x00\x00"    // little-endian, magic 42, IFD0 at offset 8
            ."\x01\x00"                           // one directory entry
            ."\x12\x01\x03\x00\x01\x00\x00\x00"   // tag 0x0112 Orientation, type SHORT, count 1
            .chr($orientation)."\x00\x00\x00"     // value (SHORT, left-justified in the 4-byte field)
            ."\x00\x00\x00\x00";                  // next-IFD offset = 0
        $payload = "Exif\x00\x00".$tiff;
        $segmentLength = strlen($payload) + 2;

        return "\xFF\xE1".chr($segmentLength >> 8).chr($segmentLength & 0xFF).$payload;
    }

    /** Read IFD0 tag 0x0112 (Orientation) from a TIFF block. null when absent or unparseable. */
    private function orientationFromTiff(string $tiff): ?int
    {
        $size = strlen($tiff);
        if ($size < 8) {
            return null;
        }
        $order = substr($tiff, 0, 2);
        if ($order === 'II') {
            $little = true;
        } elseif ($order === 'MM') {
            $little = false;
        } else {
            return null;
        }
        if ($this->uint16($tiff, 2, $little) !== 0x2A) {
            return null;
        }
        $ifd = $this->uint32($tiff, 4, $little);
        if ($ifd < 8 || $ifd + 2 > $size) {
            return null;
        }
        $count = $this->uint16($tiff, $ifd, $little);
        for ($n = 0; $n < $count; $n++) {
            $entry = $ifd + 2 + $n * 12;
            if ($entry + 12 > $size) {
                return null;
            }
            if ($this->uint16($tiff, $entry, $little) === 0x0112) {
                $value = $this->uint16($tiff, $entry + 8, $little);

                return ($value >= 1 && $value <= 8) ? $value : null;
            }
        }

        return null;
    }

    private function uint16(string $s, int $offset, bool $little): int
    {
        $a = ord($s[$offset]);
        $b = ord($s[$offset + 1]);

        return $little ? ($a | ($b << 8)) : (($a << 8) | $b);
    }

    private function uint32(string $s, int $offset, bool $little): int
    {
        $a = ord($s[$offset]);
        $b = ord($s[$offset + 1]);
        $c = ord($s[$offset + 2]);
        $d = ord($s[$offset + 3]);

        return $little
            ? ($a | ($b << 8) | ($c << 16) | ($d << 24))
            : (($a << 24) | ($b << 16) | ($c << 8) | $d);
    }

    /**
     * Chunk walk. Drops the metadata chunks (eXIf, tEXt, zTXt, iTXt — XMP rides iTXt); keeps every
     * other chunk verbatim (iCCP/gAMA included). Each input chunk's CRC is verified — a mismatch is
     * corruption and throws. IHDR must be first and IEND must terminate the stream.
     */
    private function stripPng(string $bytes): string
    {
        $signature = "\x89PNG\r\n\x1A\n";
        if (! str_starts_with($bytes, $signature)) {
            throw new ImageMetadataStripException('PNG: bad signature.');
        }
        $length = strlen($bytes);
        $drop = ['eXIf', 'tEXt', 'zTXt', 'iTXt'];
        $out = $signature;
        $i = 8;
        $first = true;

        while ($i < $length) {
            if ($i + 8 > $length) {
                throw new ImageMetadataStripException('PNG: truncated chunk header.');
            }
            $dataLength = $this->uint32($bytes, $i, false);
            $type = substr($bytes, $i + 4, 4);
            $chunkEnd = $i + 12 + $dataLength; // 4 length + 4 type + data + 4 CRC
            if ($chunkEnd > $length || $chunkEnd < $i) {
                throw new ImageMetadataStripException('PNG: chunk exceeds file length.');
            }
            if ($first && $type !== 'IHDR') {
                throw new ImageMetadataStripException('PNG: first chunk is not IHDR.');
            }
            $first = false;

            $crcGiven = substr($bytes, $i + 8 + $dataLength, 4);
            $crcActual = pack('N', crc32(substr($bytes, $i + 4, 4 + $dataLength)));
            if ($crcGiven !== $crcActual) {
                throw new ImageMetadataStripException('PNG: chunk CRC mismatch.');
            }

            if (! in_array($type, $drop, true)) {
                $out .= substr($bytes, $i, $chunkEnd - $i);
            }

            if ($type === 'IEND') {
                return $out;
            }
            $i = $chunkEnd;
        }

        throw new ImageMetadataStripException('PNG: missing IEND chunk.');
    }

    /**
     * RIFF walk. Drops the EXIF and "XMP " chunks; on a VP8X chunk clears only the EXIF (0x08) and
     * XMP (0x04) flag bits, leaving ICC/alpha/animation and reserved bits intact. Odd-sized chunks
     * carry a pad byte; the walk and the recomputed RIFF size both account for it.
     */
    private function stripWebp(string $bytes): string
    {
        $length = strlen($bytes);
        if ($length < 12 || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WEBP') {
            throw new ImageMetadataStripException('WebP: not a RIFF/WEBP container.');
        }

        $body = '';
        $i = 12;
        while ($i < $length) {
            if ($i + 8 > $length) {
                throw new ImageMetadataStripException('WebP: truncated chunk header.');
            }
            $fourCc = substr($bytes, $i, 4);
            $size = $this->uint32($bytes, $i + 4, true);
            $dataStart = $i + 8;
            if ($dataStart + $size > $length || $dataStart + $size < $dataStart) {
                throw new ImageMetadataStripException('WebP: chunk exceeds file length.');
            }
            $pad = $size & 1;
            $data = substr($bytes, $dataStart, $size);

            if ($fourCc === 'EXIF' || $fourCc === 'XMP ') {
                // dropped
            } elseif ($fourCc === 'VP8X') {
                if ($size < 1) {
                    throw new ImageMetadataStripException('WebP: malformed VP8X chunk.');
                }
                $data[0] = chr(ord($data[0]) & ~0x0C); // clear EXIF (0x08) + XMP (0x04) flags only
                $body .= 'VP8X'.pack('V', $size).$data.($pad ? "\x00" : '');
            } else {
                $body .= $fourCc.pack('V', $size).$data.($pad ? "\x00" : '');
            }

            $i = $dataStart + $size + $pad;
        }

        return 'RIFF'.pack('V', 4 + strlen($body)).'WEBP'.$body;
    }
}
