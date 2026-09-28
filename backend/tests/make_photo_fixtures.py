# -*- coding: utf-8 -*-
# Generate real image fixtures for qa_photo_processing.php (CLI PHP has no GD).
import os
from PIL import Image, ImageDraw

OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'fixtures')
os.makedirs(OUT, exist_ok=True)


def student(w=320, h=400):
    """Simple synthetic 'student' (head + shoulders on a plain background)."""
    im = Image.new('RGB', (w, h), (46, 92, 66))
    d = ImageDraw.Draw(im)
    d.ellipse([w * 0.30, h * 0.10, w * 0.70, h * 0.45], fill=(238, 232, 222))
    d.rounded_rectangle([w * 0.18, h * 0.52, w * 0.82, h], radius=40, fill=(238, 232, 222))
    return im


student().save(os.path.join(OUT, 'photo.png'))
student().save(os.path.join(OUT, 'photo.jpg'), quality=92)
student().save(os.path.join(OUT, 'photo.webp'), quality=90)

# RGBA cutout with real alpha (what the browser-side segmentation produces).
cut = Image.new('RGBA', (260, 340), (0, 0, 0, 0))
d = ImageDraw.Draw(cut)
d.ellipse([70, 20, 190, 140], fill=(238, 232, 222, 255))
d.rounded_rectangle([40, 180, 220, 340], radius=36, fill=(238, 232, 222, 255))
cut.save(os.path.join(OUT, 'cutout_transparent.png'))

# Below the 96x96 minimum -> must be rejected as low resolution.
student(80, 100).save(os.path.join(OUT, 'lowres.png'))

for f in sorted(os.listdir(OUT)):
    print(f, os.path.getsize(os.path.join(OUT, f)))
