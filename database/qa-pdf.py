"""Resume un PDF generado por Lite para el QA: páginas, tamaño, hoja y el texto de sus cadenas.

Uso: python qa-pdf.py ARCHIVO -> JSON {"paginas", "bytes", "hoja", "texto"}
Descomprime los flujos FlateDecode y junta las cadenas de texto (Tj y TJ) en Latin-1, como las escribe TCPDF
con las fuentes base. No ejecuta nada del PDF.
"""
import json
import re
import sys
import zlib

CADENA = rb'\((?:\\.|[^\\)])*\)'
datos = open(sys.argv[1], 'rb').read()
texto = []
for m in re.finditer(rb'stream\r?\n(.*?)\r?\nendstream', datos, re.S):
    try:
        flujo = zlib.decompress(m.group(1))
    except zlib.error:
        continue
    for bloque in re.finditer(rb'\[((?:' + CADENA + rb'|[^\]()])*)\]\s*TJ|(' + CADENA + rb')\s*Tj', flujo):
        partes = re.findall(CADENA, bloque.group(1) or bloque.group(2))
        contenido = b''.join(p[1:-1] for p in partes)
        contenido = re.sub(rb'\\([()\\])', rb'\1', contenido)
        texto.append(contenido.decode('latin-1'))
hoja = re.search(rb'/MediaBox\s*\[\s*[\d.]+\s+[\d.]+\s+([\d.]+)\s+([\d.]+)\s*\]', datos)
print(json.dumps({
    'paginas': len(re.findall(rb'/Type\s*/Page\b(?!s)', datos)),
    'bytes': len(datos),
    # Puntos a milímetros.
    'hoja': [round(float(hoja.group(1)) * 25.4 / 72, 1), round(float(hoja.group(2)) * 25.4 / 72, 1)] if hoja else None,
    'texto': ' | '.join(texto),
}, ensure_ascii=False))
