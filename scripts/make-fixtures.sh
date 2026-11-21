#!/usr/bin/env bash
# Regenerates the synthetic test fixtures in tests/fixtures with ImageMagick.
# All fixtures are original, generated artwork (no third-party assets).
set -euo pipefail
cd "$(dirname "$0")/../tests/fixtures"
M="${PICTOSVG_MAGICK_BIN:-magick}"
FONT="${PICTOSVG_FIXTURE_FONT:-/System/Library/Fonts/Supplemental/Arial Bold.ttf}"

# 1. Simple black logo on white: ring (has a hole), square, triangle.  600x400
"$M" -size 600x400 xc:white -fill black \
  -draw "circle 150,200 150,80" -fill white -draw "circle 150,200 150,140" \
  -fill black -draw "rectangle 320,100 420,200" -draw "polygon 460,300 560,300 510,180" logo.png

# 2. Transparent PNG logo: dark ring + bar on a transparent background.
"$M" -size 500x500 xc:none -fill "#1a1a1a" -draw "circle 250,250 250,60" \
  -alpha set -channel A -fill none -draw "alpha 250,250 point" +channel \
  \( -size 500x500 xc:none -fill black -draw "circle 250,250 250,150" \) -compose DstOut -composite \
  -compose Over -fill "#1a1a1a" -draw "rectangle 200,235 300,265" transparent-logo.png

# 3. Light artwork on transparent background (white star).
"$M" -size 400x400 xc:none -fill white -draw "polygon 200,30 245,150 370,150 270,225 310,350 200,275 90,350 130,225 30,150 155,150" light-on-transparent.png

# 4. Text-heavy image.
"$M" -size 900x420 xc:white -fill black -font "$FONT" -pointsize 96 -annotate +40+120 "PicToSVG" \
  -pointsize 44 -annotate +44+200 "Laser & CNC ready vectors" \
  -pointsize 26 -annotate +46+260 "The quick brown fox jumps over the lazy dog 0123456789" \
  -pointsize 18 -annotate +46+310 "Small print: a b c d e f g i j o p q R B 8 %% @" text.png

# 5. Detailed line drawing: thin strokes, concentric rings, hatching.
"$M" -size 800x600 xc:white -fill none -stroke black -strokewidth 3 \
  -draw "circle 250,300 250,120" -draw "circle 250,300 250,180" -draw "circle 250,300 250,240" \
  -draw "bezier 420,500 500,100 650,550 760,150" -strokewidth 2 \
  -draw "line 450,80 760,80" -draw "line 450,100 760,100" -draw "line 450,120 760,120" \
  -draw "line 450,140 760,140" -draw "roundrectangle 470,380 740,560 30,30" \
  -draw "polyline 500,540 540,420 580,520 620,410 660,530 700,400" line-drawing.png

# 6. Synthetic "photo-like" vehicle scene (gradients, soft shading, sensor-style noise), JPEG.
#    NOTE: this is generated, not a real photograph.
"$M" -size 1000x640 gradient:"#9fc4e8"-"#e9eef2" \
  \( -size 1000x220 gradient:"#6b6f73"-"#3d4043" \) -gravity south -composite -gravity northwest \
  -fill "#b3261e" -draw "roundrectangle 180,300 820,450 40,40" \
  -fill "#c9342b" -draw "polygon 300,300 380,200 640,200 730,300" \
  -fill "#cfe3f2" -draw "polygon 330,295 395,215 500,215 500,295" -draw "polygon 520,295 520,215 628,215 695,295" \
  -fill "#141414" -draw "circle 320,450 320,385" -draw "circle 680,450 680,385" \
  -fill "#b9bcc0" -draw "circle 320,450 320,420" -draw "circle 680,450 680,420" \
  -fill "#f5e9a8" -draw "ellipse 805,350 14,22 0,360" \
  -blur 0x1.2 -attenuate 0.35 +noise Gaussian -quality 86 vehicle-photo.jpg

# 7. Complex illustration: many overlapping coloured shapes.
"$M" -size 800x800 xc:"#fdf6e3" -seed 7 \
  -fill "#073642" -draw "circle 400,400 400,90" -fill "#fdf6e3" -draw "circle 400,400 400,150" \
  -fill "#cb4b16" -draw "polygon 400,180 470,360 660,360 505,470 565,650 400,540 235,650 295,470 140,360 330,360" \
  -fill "#fdf6e3" -draw "circle 400,420 400,380" -fill "#268bd2" -draw "circle 400,420 400,400" \
  -fill "#2aa198" -draw "rectangle 60,60 160,160" -draw "rectangle 640,60 740,160" \
  -draw "rectangle 60,640 160,740" -draw "rectangle 640,640 740,740" \
  -fill "#fdf6e3" -draw "circle 110,110 110,80" -draw "circle 690,110 690,80" illustration.png

# 8. WebP copy of the logo, and a noisy logo for speck clean-up tests.
"$M" logo.png logo.webp
"$M" logo.png -fill black -draw "point 30,30 point 31,30 point 580,20 rectangle 20,370 20,371 rectangle 570,350 572,352 point 300,390" noisy-logo.png
#    (1px and 1x2 specks = noise at the default setting; the 3x3 dot needs Noise Removal 9+)

# 9. JPEG stored landscape (400x200) with EXIF Orientation=6, i.e. displayed as 200x400.
#    ImageMagick does not write an EXIF block for plain JPEGs, so a minimal one is inserted.
"$M" -size 400x200 xc:white -fill black -draw "rectangle 40,40 200,160" -quality 90 rotated-src.jpg
python3 - <<'PY'
import struct
data = open('rotated-src.jpg', 'rb').read()
tiff = b'MM\x00\x2a' + struct.pack('>I', 8) + struct.pack('>H', 1) + struct.pack('>HHIHH', 0x0112, 3, 1, 6, 0) + struct.pack('>I', 0)
app1 = b'\xff\xe1' + struct.pack('>H', len(tiff) + 8) + b'Exif\x00\x00' + tiff
assert data[:2] == b'\xff\xd8'
open('rotated.jpg', 'wb').write(data[:2] + app1 + data[2:])
PY
rm -f rotated-src.jpg
ls -la
