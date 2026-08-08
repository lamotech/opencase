#!/usr/bin/env bash

set -euo pipefail

DOCS_DIR="${1:-docs}"

mkdir -p \
  "$DOCS_DIR/sager" \
  "$DOCS_DIR/dokumenter" \
  "$DOCS_DIR/soegning" \
  "$DOCS_DIR/administrator"

create_page() {
  local path="$1"
  local title="$2"

  if [[ -e "$path" ]]; then
    echo "Springer over eksisterende fil: $path"
    return
  fi

  cat > "$path" <<EOF
# $title

Denne side er under udarbejdelse.
EOF

  echo "Oprettet: $path"
}

create_config() {
  local path="$1"
  local content="$2"

  if [[ -e "$path" ]]; then
    echo "Springer over eksisterende fil: $path"
    return
  fi

  printf '%s\n' "$content" > "$path"
  echo "Oprettet: $path"
}

# Forside og generelle sider
create_page "$DOCS_DIR/index.md" "OpenCase dokumentation"
create_page "$DOCS_DIR/oversigt.md" "Oversigt"
create_page "$DOCS_DIR/favoritter.md" "Favoritter"
create_page "$DOCS_DIR/indkommende-dokumenter.md" "Indkommende dokumenter"
create_page "$DOCS_DIR/dashboard-widgets.md" "Dashboard-widgets"
create_page "$DOCS_DIR/ai-handlinger.md" "AI-handlinger"

# Sager
create_page "$DOCS_DIR/sager/index.md" "Sager"
create_page "$DOCS_DIR/sager/opret-sag.md" "Opret en sag"
create_page "$DOCS_DIR/sager/parter.md" "Parter"
create_page "$DOCS_DIR/sager/journalnotater.md" "Journalnotater"
create_page "$DOCS_DIR/sager/andre-sagsbehandlere.md" "Andre sagsbehandlere"
create_page "$DOCS_DIR/sager/adgang.md" "Adgang"
create_page "$DOCS_DIR/sager/sagshierarki.md" "Sagshierarki"

# Dokumenter
create_page "$DOCS_DIR/dokumenter/index.md" "Dokumenter"
create_page "$DOCS_DIR/dokumenter/opret-dokument.md" "Opret dokument"
create_page "$DOCS_DIR/dokumenter/upload-fil.md" "Upload fil"
create_page "$DOCS_DIR/dokumenter/ny-fil-fra-skabelon.md" "Ny fil fra skabelon"
create_page "$DOCS_DIR/dokumenter/kontakter.md" "Kontakter"
create_page "$DOCS_DIR/dokumenter/noter.md" "Noter"
create_page "$DOCS_DIR/dokumenter/workflow.md" "Workflow"
create_page "$DOCS_DIR/dokumenter/rediger-fil.md" "Rediger fil"
create_page "$DOCS_DIR/dokumenter/filversioner.md" "Filversioner"
create_page "$DOCS_DIR/dokumenter/del-fil.md" "Del fil"
create_page "$DOCS_DIR/dokumenter/del-dokument.md" "Del dokument"
create_page "$DOCS_DIR/dokumenter/digital-post.md" "Digital post"
create_page "$DOCS_DIR/dokumenter/paamindelser.md" "Påmindelser"

# Søgning
create_page "$DOCS_DIR/soegning/index.md" "Søgning"
create_page "$DOCS_DIR/soegning/borger.md" "Søg efter borger"
create_page "$DOCS_DIR/soegning/virksomhed.md" "Søg efter virksomhed"

# Administratorvejledning
create_page "$DOCS_DIR/administrator/index.md" "Installation og administration"
create_page "$DOCS_DIR/administrator/installation.md" "Installation"
create_page "$DOCS_DIR/administrator/opgradering.md" "Opgradering"
create_page "$DOCS_DIR/administrator/administrer-skabeloner.md" "Administrer skabeloner"
create_page "$DOCS_DIR/administrator/konfiguration.md" "Konfiguration"
create_page "$DOCS_DIR/administrator/fejlfinding.md" "Fejlfinding"

# Navigation for hele dokumentationen
create_config "$DOCS_DIR/.pages" \
"title: OpenCase dokumentation
nav:
  - index.md
  - oversigt.md
  - sager
  - dokumenter
  - favoritter.md
  - soegning
  - indkommende-dokumenter.md
  - dashboard-widgets.md
  - ai-handlinger.md
  - administrator"

# Navigation for Sager
create_config "$DOCS_DIR/sager/.pages" \
"title: Sager
nav:
  - index.md
  - opret-sag.md
  - parter.md
  - journalnotater.md
  - andre-sagsbehandlere.md
  - adgang.md
  - sagshierarki.md"

# Navigation for Dokumenter
create_config "$DOCS_DIR/dokumenter/.pages" \
"title: Dokumenter
nav:
  - index.md
  - opret-dokument.md
  - upload-fil.md
  - ny-fil-fra-skabelon.md
  - kontakter.md
  - noter.md
  - workflow.md
  - rediger-fil.md
  - filversioner.md
  - del-fil.md
  - del-dokument.md
  - digital-post.md
  - paamindelser.md"

# Navigation for Søgning
create_config "$DOCS_DIR/soegning/.pages" \
"title: Søgning
nav:
  - index.md
  - borger.md
  - virksomhed.md"

# Navigation for Administratorvejledning
create_config "$DOCS_DIR/administrator/.pages" \
"title: Installation og administration
nav:
  - index.md
  - installation.md
  - opgradering.md
  - administrer-skabeloner.md
  - konfiguration.md
  - fejlfinding.md"

echo
echo "Dokumentationsstrukturen er oprettet under: $DOCS_DIR"
echo
echo "Start lokal forhåndsvisning med:"
echo "  mkdocs serve --dev-addr 0.0.0.0:8000"