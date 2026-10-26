# Video MyAduan (Remotion)

- `S01`: gimik pelancaran 6 saat (bunyi dijana oleh `sfx.py`).
- `S02`–`S14`: scene video penerangan. `Penuh`: semua scene disambung (6 minit).
- Skrip, masa dan penerangan setiap scene: `src/scenes.json`.
- Gambar storyboard (2 setiap scene): `storyboard/`.

```bash
npm i
npm run studio      # pratonton
npm run gimik       # render video gimik
npm run penuh       # render draf video penerangan (tanpa suara latar)
npm run stills      # render semula gambar storyboard ke stills/
```

Folder ini bukan sebahagian daripada plugin (`bin/build-zip.sh` tak masukkan dia).
