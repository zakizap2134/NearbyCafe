"""Developer QA: python tests/launcher-assets.py (Pillow required)."""
from pathlib import Path
from PIL import Image,ImageChops
import hashlib,json,xml.etree.ElementTree as ET,re
root=Path(__file__).resolve().parents[1];icons=root/'launcher/icons';static=root/'static'
meta=json.loads((icons/'PROVENANCE.json').read_text());src=root/meta['logo_source']
assert hashlib.sha256(src.read_bytes()).hexdigest()==meta['sha256']
original=Image.open(src).convert('RGBA')
def expected(n):
 im=original.copy();im.thumbnail((n,n),Image.Resampling.LANCZOS);out=Image.new('RGBA',(n,n));out.alpha_composite(im,((n-im.width)//2,(n-im.height)//2));return out
ico=Image.open(icons/'nearby-cafe.ico');assert ico.ico.sizes()=={(n,n) for n in meta['ico_sizes']}
for n in meta['ico_sizes']:
 assert ico.ico.getimage((n,n)).size==(n,n)
for n in [16,32,48,192]:
 im=Image.open(static/f'favicon-{n}.png').convert('RGBA');assert ImageChops.difference(im,expected(n)).getbbox() is None
 assert (root/'public'/f'favicon-{n}.png').read_bytes()==(static/f'favicon-{n}.png').read_bytes()
assert (static/'favicon.ico').read_bytes()==(icons/'nearby-cafe.ico').read_bytes()==(root/'public/favicon.ico').read_bytes()
for name in ['checking','database','coffee','ready','warning','open','retry','stop','stopped','shared']:
 ET.fromstring((icons/(name+'.svg')).read_text());assert Image.open(icons/(name+'.png')).size==(96,96)
text=(root/'launcher/NearbyCafe.ps1').read_text(encoding='utf-8-sig');xaml=text.split("$xaml=@'\n",1)[1].split("\n'@",1)[0];ET.fromstring(xaml)
assert 'ScrollViewer' in xaml and 'TextWrapping="Wrap"' in xaml
html=(root/'public/index.html').read_text();assert '<title>Nearby Café</title>' in html
for asset in re.findall(r'(?:src|href)="\./(assets/[^"]+)"',html):assert (root/'public'/asset).is_file()
def lum(hex):
 cs=[int(hex[i:i+2],16)/255 for i in (0,2,4)];cs=[v/12.92 if v<=.04045 else ((v+.055)/1.055)**2.4 for v in cs];return sum(a*b for a,b in zip(cs,[.2126,.7152,.0722]))
contrast=(lum('ffffff')+.05)/(lum('a93226')+.05);assert contrast>=4.5
print('PASS original logo hash unchanged; full-canvas proportional PNG conversion; multi-resolution ICO 16–256; local production assets match source')
print('PASS local status/action SVG and 96px PNG assets; well-formed scrollable WPF XAML and visible labels')
print(f'PASS white-on-muted-red button contrast {contrast:.2f}:1')
print('PENDING native Windows visual checks at 100/125/150/200% scaling. The full wordmark is naturally tiny at 16px; no logo cropping or redesign was applied.')
