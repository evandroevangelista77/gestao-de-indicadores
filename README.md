# Gestão de Indicadores

Plugin **Indicadores** para GLPI 11 e 12: painel executivo da qualidade dos departamentos, auditorias programadas e relatório executivo em PDF com planos de ação para os desvios.

## O que faz

- **Painel executivo.** Nota de 0 a 100 de cada área, mês a mês, com o índice geral. Ao passar o mouse numa célula abre um balão com os indicadores do mês, a meta e a tendência de 12 meses; o clique abre o detalhe da área.
- **Áreas que vêm com o plugin** (cada uma lê o plugin correspondente e some do painel se ele não estiver instalado): PMO · Projetos, Sistemas Internos, Reembolso, Comercial (plugin CRM), Ciclo Financeiro, Gestão de Eventos, RH, Controle de Equipamentos, CSAT e e-NPS.
- **KPIs por área.** Menu *Indicadores → KPIs*: a planilha mensal de cada área debaixo da Superintendência, com peso, meta e frequência (mensal, quadrimestral ou anual), realizado de cada mês, atingimento com semáforo e resultado ponderado, mais a aba Consolidado. O catálogo nasce da **importação da planilha do painel** (uma aba por área: Nome, Nome Indicador, Meta, Peso, Frequência, Observação) e pode ser reimportado sem perder os valores lançados. O resultado de cada área alimenta a linha da Superintendência no painel executivo. Os valores podem ser **coletados automaticamente** (chamados e SLAs, satisfação, projetos, Ciclo Financeiro, Zabbix): em *Configuração → Coleta automática* defina o escopo dos chamados de cada área e a fonte de cada KPI; lançamento manual sempre tem prioridade.
- **Áreas personalizadas.** Inclua quantas áreas quiser, em *Indicadores → Áreas do painel*:
  - **Manual**: você define os indicadores (unidade, direção, meta, limite, peso) e lança os valores de cada mês numa grade;
  - **Chamados do GLPI**: indicadores de atendimento calculados a partir dos chamados de uma entidade (com as filhas), de um grupo atribuído e/ou de uma categoria.
- **Auditorias programadas.** Quinzenal, mensal, bimestral, trimestral, semestral ou anual, para uma área ou todas. Rodam sozinhas, avaliam o período que acabou de fechar, registram nota, evolução e achados e, se quiser, abrem um chamado para o responsável quando a nota fica abaixo do mínimo.
- **Relatório executivo (PDF).** Capa, visão da diretoria em uma página, decisões com responsável e prazo, roadmap de 90 dias e anexos por área com leitura, indicadores com tendência e planos de ação para cada desvio.
- **Metas e limites** editáveis por indicador, com peso na nota da área.

## Como a nota é calculada

Cada indicador tem **meta** (verde) e **limite** (onde passa de amarelo a vermelho). Valor na meta = 100; entre a meta e o limite = 50 a 100; além do limite = 0 a 50. A nota da área é a média das notas dos indicadores ponderada pelo peso; indicadores informativos não pontuam. O índice geral é a média das áreas. As faixas (saudável, atenção, crítica) são configuráveis.

Indicadores de **situação atual** (pendências e atrasos abertos) só existem para o mês corrente; nos meses passados valem as fotos gravadas pelo plugin. A ação automática `indicadoresfoto` grava a foto do mês a cada 6 horas e `indicadoresauditoria` executa as auditorias vencidas a cada hora, então o cron do GLPI precisa estar ativo.

## Requisitos

- GLPI 11.0 ou 12.0 (PHP 8.2 ou superior, como o próprio GLPI).
- As áreas padrão dependem dos plugins correspondentes; sem eles, a área simplesmente não aparece.

## Instalação

Pelo pacote:

```bash
./tools/empacotar.sh                      # gera dist/indicadores-<versão>.tgz
# no servidor GLPI:
sudo tar -xzf indicadores-<versão>.tgz -C /var/www/glpi/plugins
sudo chown -R www-data:www-data /var/www/glpi/plugins/indicadores
cd /var/www/glpi
sudo -u www-data php bin/console plugin:install indicadores
sudo -u www-data php bin/console plugin:activate indicadores
```

Ou direto por SSH, a partir de uma cópia do repositório:

```bash
GLPI_SSH=usuario@servidor ./tools/instalar.sh
```

O menu lateral ganha a seção **Indicadores** (Painel executivo, Auditorias, Áreas do painel, Metas e limites e Configuração). Para atualizar, publique a nova versão e rode `plugin:install` de novo: as tabelas são criadas só quando faltam e o histórico é preservado.

## Estrutura

```
indicadores/            o plugin (copie esta pasta para <GLPI>/plugins/)
  setup.php, hook.php   registro, instalação e desinstalação
  src/Coletor/          um coletor por área (indicadores e consultas)
  src/Motor.php         coleta, fotos mensais e notas
  src/Relatorio*.php    PDF executivo (TCPDF, que já vem com o GLPI)
  src/Planos.php        biblioteca de planos de ação por indicador
  front/                telas e o endereço JSON do balão
  public/               CSS e JavaScript (sem bibliotecas externas)
tools/                  empacotar e instalar por SSH
```

Para incluir uma área nova no código, crie um coletor em `src/Coletor/` e registre-o em `Motor::COLETORES`.

## Desenvolvimento

Não há dependências a instalar: o plugin usa o que o GLPI já traz (TCPDF, Tabler Icons) e JavaScript puro. O fluxo de integração contínua confere a sintaxe PHP (8.2 e 8.3) e JavaScript.

## Licença

GPLv3 ou posterior. Veja [LICENSE](LICENSE).
