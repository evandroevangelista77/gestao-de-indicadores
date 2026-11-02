#!/usr/bin/env bash
# Gera dist/indicadores-<versão>.tgz, pronto para extrair em <GLPI>/plugins/.
set -euo pipefail

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSAO="$(grep -oE "PLUGIN_INDICADORES_VERSION', '[0-9.]+" "$RAIZ/indicadores/setup.php" | grep -oE '[0-9.]+$')"
mkdir -p "$RAIZ/dist"
SAIDA="$RAIZ/dist/indicadores-$VERSAO.tgz"

# COPYFILE_DISABLE evita arquivos de metadados do macOS dentro do pacote
COPYFILE_DISABLE=1 tar -C "$RAIZ" --exclude='.DS_Store' -czf "$SAIDA" indicadores
echo "Pacote gerado: $SAIDA"
