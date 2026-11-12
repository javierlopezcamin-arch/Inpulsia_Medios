#!/bin/sh
# Regenerates languages/inpulsia-medios.pot, merges it into each .po and compiles the .mo files.
# Requires gettext (xgettext, msgmerge, msgfmt). Then translate the new empty msgstr entries in each .po and run again.
set -e
cd "$(dirname "$0")/.."
xgettext --language=PHP --from-code=UTF-8 --no-wrap \
	--keyword=__ --keyword=_e --keyword=esc_html__ --keyword=esc_html_e --keyword=esc_attr__ --keyword=esc_attr_e --keyword=_n:1,2 \
	--package-name="Inpulsia Medios" -o languages/inpulsia-medios.pot inpulsia-medios.php includes/*.php
for po in languages/*.po; do
	msgmerge --quiet --update --no-fuzzy-matching --backup=none "$po" languages/inpulsia-medios.pot
	msgfmt -o "${po%.po}.mo" "$po"
done
echo "Pendientes de traducir:"; for po in languages/*.po; do printf '%s: ' "$po"; msgattrib --untranslated --no-obsolete "$po" | grep -c '^msgid "..*"' || true; done
