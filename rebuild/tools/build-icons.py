"""Developer-only: python -m pip install pillow cairosvg; python tools/build-icons.py.
Uses the complete supplied logo canvas, preserving its proportions and colors.
No external images, fonts or runtime downloads.
"""
from pathlib import Path
from PIL import Image
import cairosvg,hashlib,json
root=Path(__file__).resolve().parents[1];src=root/'src/assets/nearby-cafe-logo.png';icons=root/'launcher/icons';static=root/'static'
icons.mkdir(exist_ok=True);static.mkdir(exist_ok=True)
original=Image.open(src).convert('RGBA')
def fit(size):
 im=original.copy();im.thumbnail((size,size),Image.Resampling.LANCZOS);out=Image.new('RGBA',(size,size),(0,0,0,0));out.alpha_composite(im,((size-im.width)//2,(size-im.height)//2));return out
sizes=[16,24,32,48,64,128,256]
fit(256).save(icons/'nearby-cafe.ico',sizes=[(n,n) for n in sizes],append_images=[fit(n) for n in sizes[:-1]])
(static/'favicon.ico').write_bytes((icons/'nearby-cafe.ico').read_bytes());fit(256).save(icons/'nearby-cafe-256.png')
for n in [16,32,48,192]:fit(n).save(static/f'favicon-{n}.png')
fit(180).save(static/'apple-touch-icon.png')
paths={
 'checking':'<circle cx="10" cy="10" r="6"/><path d="m15 15 6 6"/>',
 'database':'<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 4 16 4 16 0V5M4 12c0 4 16 4 16 0"/>',
 'coffee':'<path d="M4 8h13v8a5 5 0 0 1-5 5H9a5 5 0 0 1-5-5ZM17 9h2a3 3 0 0 1 0 6h-2M7 2v3M12 2v3"/>',
 'ready':'<circle cx="12" cy="12" r="9"/><path d="m7 12 3 3 7-7"/>',
 'warning':'<path d="m12 3 10 18H2ZM12 9v5M12 17v1"/>',
 'stopped':'<circle cx="12" cy="12" r="9"/><path d="M8 8h8v8H8Z"/>',
 'shared':'<circle cx="12" cy="12" r="9"/><path d="M12 10v7M12 6v1"/>',
 'open':'<path d="M14 3h7v7M21 3 11 13M10 5H4v16h16v-6"/>',
 'retry':'<path d="M20 8a9 9 0 1 0 1 7M20 2v6h-6"/>',
 'stop':'<path d="M5 5h14v14H5Z"/>'}
for name,path in paths.items():
 color='#ffffff' if name in ['open','retry','stop'] else '#28734d' if name=='ready' else '#a93226'
 svg=f'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="{color}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">{path}</svg>'
 (icons/(name+'.svg')).write_text(svg)
 cairosvg.svg2png(bytestring=svg.encode(),write_to=str(icons/(name+'.png')),output_width=96,output_height=96)
(icons/'PROVENANCE.json').write_text(json.dumps({'logo_source':'src/assets/nearby-cafe-logo.png','sha256':hashlib.sha256(src.read_bytes()).hexdigest(),'ico_sizes':sizes,'method':'Entire canvas resized with Lanczos; no crop, recolor or stretch. Status/action SVGs authored locally.'},indent=2)+'\n')
print('Built local logo ICO/PNG and status/action icons.')
