# Changelog

## 1.4.0
- **Coleta automática dos KPIs por área** (Configuração → Coleta automática): para cada área, escopo dos chamados (entidade com as filhas, grupo e categoria) e, para cada KPI, a fonte do valor: chamados solucionados no prazo (geral, incidentes e requisições), avaliação positiva e negativa dos chamados e dos projetos, tarefas de projeto no prazo, fases do Ciclo Financeiro dentro do SLO e devolvidas, KPIs lançados no prazo e SLI do Zabbix.
- Sugestão automática de fontes pelo nome dos KPIs, coleta a cada 6 horas e botões "Coletar agora".
- Valor lançado à mão tem prioridade sobre o automático; a planilha marca os valores automáticos.

## 1.3.0
- Menu **KPIs**: painel de KPIs por área debaixo da Superintendência. Cada área tem a planilha mensal com peso, meta, frequência (mensal, quadrimestral ou anual) e o realizado de cada mês, com atingimento, semáforo e resultado ponderado; a aba Consolidado mostra todas as áreas e a superintendência.
- Importação da planilha do painel (Nome, Nome Indicador, Meta, Peso, Frequência, Observação, uma aba por área), repetível sem perder os valores lançados.
- A Superintendência Operacional entra no painel executivo com o resultado dos KPIs de cada área.

## 1.2.0
- Área **Comercial** no painel executivo, lida do plugin CRM: taxa de conversão, ciclo de venda, oportunidades paradas, prazos de edital vencidos, pipeline e previsão ponderada.

## 1.1.0
- Áreas personalizadas: inclua áreas no painel, do tipo manual (valores lançados mês a mês) ou chamados do GLPI (calculada por entidade, grupo e categoria).
- Relatório executivo em PDF: capa, visão da diretoria em uma página, decisões com responsável e prazo, roadmap de 90 dias, anexos por área com planos de ação para os desvios.
- Painel: cartões de resumo com números grandes, mini-gráficos e descrições; mapa mês a mês com células de tamanho uniforme.

## 1.0.0
- Painel executivo mês a mês com balão de indicadores ao passar o mouse.
- Nove áreas: PMO, Sistemas Internos, Reembolso, Ciclo Financeiro, Gestão de Eventos, RH, Controle de Equipamentos, CSAT e e-NPS.
- Auditorias programadas: quinzenal, mensal, bimestral, trimestral, semestral e anual.
- Metas, limites e pesos editáveis.
