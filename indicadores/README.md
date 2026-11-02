# Indicadores (plugin GLPI 11 e 12)

Painel executivo da qualidade dos departamentos, com auditorias programadas.

## O que faz

- **Painel executivo**: nota (0 a 100) de cada área, mês a mês, com o índice geral. Ao passar o mouse numa célula abre um balão com os indicadores do mês, a meta e a tendência de 12 meses; o clique abre o detalhe da área com um gráfico por indicador.
- **Áreas** (lidas dos plugins instalados; a área some se o plugin não existe): PMO · Projetos, Sistemas Internos, Reembolso, Ciclo Financeiro, Gestão de Eventos, RH, Controle de Equipamentos, CSAT e e-NPS.
- **Auditorias programadas**: quinzenal, mensal, bimestral, trimestral, semestral ou anual, para uma área ou todas. Cada execução avalia o período que acabou de fechar, registra a nota, a evolução e os achados (indicadores em atenção ou críticos) e, se quiser, abre um chamado para o responsável quando a nota fica abaixo do mínimo.
- **Metas e limites**: meta, limite, peso e ativação de cada indicador são editáveis.

## Como a nota é calculada

Cada indicador tem meta (verde) e limite (onde passa de amarelo a vermelho). Valor na meta = 100; entre a meta e o limite = 50 a 100; além do limite = 0 a 50. A nota da área é a média ponderada pelo peso; indicadores informativos não entram. O índice geral é a média das áreas.

Indicadores de **situação atual** (pendências e atrasos abertos) só existem para o mês corrente; nos meses passados valem as fotos gravadas pelo plugin. O plugin grava a foto do mês a cada 6 horas (ação automática `indicadoresfoto`) e executa as auditorias vencidas (`indicadoresauditoria`).

## Instalar

```bash
COPYFILE_DISABLE=1 tar czf indicadores.tgz indicadores
# no servidor, em /var/www/glpi/plugins:
sudo tar xzf indicadores.tgz && sudo chown -R www-data:www-data indicadores
cd /var/www/glpi
sudo -u www-data php bin/console plugin:install indicadores
sudo -u www-data php bin/console plugin:activate indicadores
```

O menu lateral ganha a seção **Indicadores** (Painel executivo, Auditorias, Metas e limites, Configuração).

## Estrutura

- `src/Coletor/*`: um coletor por área (indicadores e consultas). Para incluir uma área nova, crie um coletor e registre-o em `Motor::COLETORES`.
- `src/Motor.php`: coleta, fotos mensais e notas. `src/Periodo.php`: períodos das auditorias. `src/Score.php`: nota e semáforo.
- `src/Auditoria.php`, `src/Execucao.php`, `src/Meta.php`, `src/Painel.php`, `src/Rotina.php`.
- `front/`: telas e o endereço JSON do balão (`ajax.php`). `public/`: CSS e JavaScript.

Licença GPLv3+.
