#!/usr/bin/env bash
# Publica o plugin num servidor GLPI por SSH e instala ou atualiza.
#
#   GLPI_SSH=usuario@servidor ./tools/instalar.sh
#
# Variáveis: GLPI_SSH (obrigatória), GLPI_DIR (padrão /var/www/glpi), GLPI_WEB_USER (padrão www-data).
# O usuário SSH precisa de sudo. Nada é guardado no repositório: servidor e credenciais ficam com você.
set -euo pipefail

: "${GLPI_SSH:?Informe o servidor: GLPI_SSH=usuario@servidor ./tools/instalar.sh}"
GLPI_DIR="${GLPI_DIR:-/var/www/glpi}"
GLPI_WEB_USER="${GLPI_WEB_USER:-www-data}"
RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

"$RAIZ/tools/empacotar.sh" >/dev/null
PACOTE="$(ls -t "$RAIZ"/dist/indicadores-*.tgz | head -n1)"
scp -q "$PACOTE" "$GLPI_SSH:/tmp/indicadores.tgz"

ssh "$GLPI_SSH" "set -e
cd '$GLPI_DIR/plugins'
sudo rm -rf indicadores.novo && sudo mkdir indicadores.novo
sudo tar --warning=no-timestamp -xzf /tmp/indicadores.tgz -C indicadores.novo --strip-components=1
for f in \$(find indicadores.novo -name '*.php'); do php -l \"\$f\" >/dev/null; done
sudo rm -rf indicadores && sudo mv indicadores.novo indicadores
sudo chown -R '$GLPI_WEB_USER:$GLPI_WEB_USER' indicadores
cd '$GLPI_DIR'
sudo -u '$GLPI_WEB_USER' php bin/console plugin:install indicadores
sudo -u '$GLPI_WEB_USER' php bin/console plugin:activate indicadores
sudo -u '$GLPI_WEB_USER' php bin/console plugin:list | grep -i indicadores
rm -f /tmp/indicadores.tgz"
echo "Plugin Indicadores publicado em $GLPI_SSH"
