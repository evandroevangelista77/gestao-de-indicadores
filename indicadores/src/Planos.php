<?php

namespace GlpiPlugin\Indicadores;

/**
 * Biblioteca de planos de ação por indicador: o que está em jogo, causas prováveis, ações recomendadas e
 * quem deve conduzir. O relatório executivo escolhe os planos dos indicadores que fugiram da meta, define
 * a prioridade (P1, P2, P3) pelo tamanho do desvio e pelo peso, e monta os prazos de 15, 30, 60 e 90 dias.
 */
class Planos
{
    public const COR = ['P1' => '#dc2626', 'P2' => '#ea580c', 'P3' => '#ca8a04'];

    /** código do indicador => [risco, [causas], [ações], responsável sugerido] */
    private const BASE = [
        'comercial.conversao' => ['Conversão baixa significa esforço comercial sem retorno e previsão de receita menos confiável.',
            ['Oportunidades pouco qualificadas entrando no funil', 'Propostas sem acompanhamento até a decisão do cliente'],
            ['Revisar os critérios de qualificação e descartar cedo o que não tem aderência', 'Analisar os motivos de perda e ajustar proposta, preço e argumento', 'Definir cadência de acompanhamento das propostas enviadas'], 'Gestão comercial'],
        'comercial.ciclo' => ['Ciclo de venda longo adia a receita e consome capacidade da equipe comercial e de pré-venda.',
            ['Etapas de pré-venda e orçamento com tempo parado', 'Decisão do cliente sem próximo passo combinado'],
            ['Mapear o tempo médio por etapa do funil e atacar a mais lenta', 'Exigir próximo passo e data em toda oportunidade aberta', 'Padronizar modelos de proposta e orçamento para reduzir a elaboração'], 'Gestão comercial e pré-venda'],
        'comercial.paradas' => ['Oportunidades paradas esfriam e tendem a ser perdidas para a concorrência.',
            ['Falta de rotina de acompanhamento pelo atendente', 'Retorno do cliente pendente sem cobrança'],
            ['Definir a regra: oportunidade sem movimentação há 7 dias recebe contato e novo prazo', 'Reatribuir ou encerrar as que não têm responsável ativo', 'Revisar o funil semanalmente no comitê comercial'], 'Gestão comercial'],
        'comercial.prazos' => ['Prazo de edital ou proposta vencido pode significar perda definitiva da oportunidade.',
            ['Prazos não monitorados pela equipe', 'Documentação de habilitação ou proposta atrasada'],
            ['Priorizar hoje as oportunidades com prazo vencido: confirmar se ainda cabe participação', 'Registrar todos os prazos no CRM com alerta antecipado', 'Escalar à gestão os casos que dependem de documentação de outras áreas'], 'Gestão comercial e jurídico'],
        'pmo.prazo' => ['Atrasos recorrentes corroem a previsibilidade das entregas e a credibilidade do PMO.',
            ['Estimativas otimistas e sem reserva de contingência', 'Dependências entre tarefas sem gestão e replanejamentos sem registro'],
            ['Revisar as tarefas vencidas e replanejar com data de comprometimento aprovada pelo gerente do projeto', 'Instituir revisão semanal de prazos com escalonamento das tarefas a 3 dias do vencimento', 'Registrar a causa-raiz de cada atraso e ajustar a contingência dos próximos planos'], 'Gerente do PMO'],
        'pmo.saude' => ['Projetos com tarefas vencidas ou fora do prazo tendem a estourar escopo e custo.',
            ['Tarefas vencidas sem replanejamento', 'Projetos sem atualização de status ou sem responsável ativo'],
            ['Conduzir comitê quinzenal de portfólio para decidir replanejar, reforçar ou encerrar cada projeto vermelho', 'Exigir atualização semanal do percentual concluído e do status', 'Congelar ou encerrar formalmente os projetos sem patrocinador ativo'], 'Gerente do PMO e patrocinadores'],
        'pmo.atrasadas' => ['O acúmulo de tarefas vencidas esconde riscos de entrega e sobrecarrega a equipe.',
            ['Capacidade da equipe abaixo da demanda', 'Tarefas antigas que nunca foram baixadas no sistema'],
            ['Fazer mutirão de saneamento: concluir, replanejar ou cancelar cada tarefa vencida', 'Redistribuir tarefas conforme a carga de cada responsável', 'Adotar a regra: tarefa vencida há mais de 15 dias exige replanejamento'], 'Gerentes de projeto'],
        'pmo.riscos' => ['Riscos críticos sem resposta podem virar problemas com impacto financeiro e de prazo.',
            ['Plano de resposta inexistente ou sem dono', 'Riscos não revisitados desde o registro'],
            ['Definir responsável, resposta e data para cada risco crítico', 'Revisar a matriz de riscos em cada comitê e escalar à diretoria os que não avançam', 'Reservar contingência de prazo e orçamento para os riscos que não puderem ser eliminados'], 'Gerente do PMO'],
        'si.sla' => ['Descumprir o prazo de solução afeta a continuidade dos sistemas internos e a confiança das áreas usuárias.',
            ['Chamados sem priorização por criticidade', 'Dependência de terceiros sem SLA contratual'],
            ['Revisar a fila diariamente, atacando primeiro o que vence em 24 horas', 'Cobrar SLA dos prestadores e marcar as pendências de terceiros', 'Automatizar alertas de vencimento para o responsável e o líder do time'], 'Líder de Sistemas Internos'],
        'si.tempo' => ['Tempo de solução alto prolonga a indisponibilidade e gera retrabalho.',
            ['Diagnóstico inicial incompleto', 'Falta de base de conhecimento para incidentes recorrentes'],
            ['Documentar a solução dos 10 incidentes mais frequentes', 'Padronizar a triagem com checklist de dados mínimos no chamado', 'Analisar os chamados acima do tempo médio e tratar a causa-raiz'], 'Líder de Sistemas Internos'],
        'si.vencidos' => ['Chamados vencidos violam o acordo de serviço e escalam para a diretoria.',
            ['Capacidade abaixo da demanda', 'Chamados parados à espera do solicitante ou de terceiros'],
            ['Zerar os vencidos em 15 dias, começando pelos mais antigos', 'Definir o status "pendente" com prazo e cobrança automática', 'Escalar à gestão o que depende de decisão ou de fornecedor'], 'Líder de Sistemas Internos'],
        'si.backlog' => ['Fila crescente indica demanda acima da capacidade e antecipa vencimentos de SLA.',
            ['Entrada de chamados maior que a capacidade de atendimento', 'Chamados antigos sem encerramento'],
            ['Dimensionar a capacidade do time e redistribuir as demandas', 'Encerrar chamados inativos com aviso ao solicitante', 'Atacar os incidentes recorrentes para reduzir a entrada'], 'Líder de Sistemas Internos'],
        'si.falhas' => ['Falhas nas automações do Zabbix reduzem a confiabilidade do monitoramento.',
            ['Credenciais ou permissões da integração vencidas ou insuficientes', 'Alterações em lote sem validação prévia'],
            ['Revisar o registro das ações com erro e corrigir a causa mais frequente', 'Validar tokens e permissões do usuário de integração', 'Incluir teste de validação antes de aplicar alterações em lote'], 'Time de Monitoramento'],
        'reembolso.aprovacao' => ['Aprovação lenta atrasa o pagamento ao técnico, gera insatisfação e risco trabalhista.',
            ['Gestores sem rotina de aprovação', 'Solicitações incompletas que voltam para correção'],
            ['Definir SLA de 2 dias para aprovação com lembrete automático ao gestor', 'Designar aprovador substituto para ausências', 'Reforçar o preenchimento correto (comprovantes e quilometragem) com o time técnico'], 'Gestores de área e Financeiro'],
        'reembolso.pagamento' => ['Pagamento tardio compromete a confiança dos técnicos e a imagem da empresa.',
            ['Fila do financeiro concentrada em datas fixas', 'Lotes sem conferência prévia'],
            ['Antecipar a conferência dos lotes aprovados', 'Fixar um ciclo de pagamento (semanal ou quinzenal) e comunicá-lo', 'Acompanhar semanalmente o estoque de lotes aprovados e não pagos'], 'Financeiro'],
        'reembolso.recusa' => ['Alta recusa indica falha de orientação ou de controle das despesas.',
            ['Política de reembolso pouco clara', 'Despesas sem comprovante ou fora da política'],
            ['Publicar um guia rápido com as despesas aceitas e os limites', 'Analisar os motivos de recusa e treinar os solicitantes que mais erram', 'Validar as regras já no envio (comprovante obrigatório e limites por tipo)'], 'Financeiro e OTC'],
        'reembolso.devolvidos' => ['Lotes devolvidos geram retrabalho e atrasam o ciclo de pagamento.',
            ['Inconsistências de quilometragem, datas ou comprovantes', 'Conferência tardia nas etapas'],
            ['Mapear os motivos de devolução por etapa', 'Incluir checklist antes do envio do lote', 'Antecipar a conferência para a etapa do gestor'], 'Gestores de área e Financeiro'],
        'reembolso.parados' => ['Solicitações paradas atrasam o pagamento e acumulam passivo com os técnicos.',
            ['Gestor sem notificação ou ausente', 'Fluxo sem escalonamento'],
            ['Cobrar os aprovadores pendentes e definir substituto', 'Escalar ao gestor superior o que passa de 5 dias', 'Revisar as notificações do fluxo'], 'Gestores de área'],
        'ciclo.slo' => ['Fases fora do SLO alongam o ciclo financeiro e adiam o recebimento.',
            ['Fase com capacidade limitada ou dependente de outra área', 'SLO desalinhado com a realidade da operação'],
            ['Identificar as três fases que mais estouram o SLO e tratar o gargalo de cada uma', 'Revisar o SLO das fases com as áreas responsáveis', 'Acompanhar semanalmente as fases ativas próximas do limite'], 'Gestor do Ciclo Financeiro'],
        'ciclo.duracao' => ['Ciclo longo imobiliza capital de giro e retarda a receita.',
            ['Passagens de bastão com tempo parado entre áreas', 'Documentos e aceites que chegam incompletos'],
            ['Mapear o tempo parado entre as fases e eliminar esperas', 'Exigir lista de documentos mínima antes de iniciar cada fase', 'Definir meta de duração por tipo de venda e acompanhá-la no comitê'], 'Gestor do Ciclo Financeiro'],
        'ciclo.devolucoes' => ['Devoluções entre áreas indicam entrada incompleta e geram retrabalho.',
            ['Informações faltantes na origem da venda', 'Critérios de aceite entre fases pouco claros'],
            ['Padronizar a entrada de cada fase com checklist de aceite', 'Analisar as devoluções por motivo e corrigir na origem', 'Treinar comercial e operação nos requisitos de cada fase'], 'Gestor do Ciclo Financeiro e Comercial'],
        'ciclo.fora' => ['Ciclos parados fora do SLO atrasam o faturamento e o recebimento.',
            ['Fase ativa sem responsável acionado', 'Dependência externa sem cobrança'],
            ['Acionar o responsável de cada fase estourada e registrar o motivo', 'Escalar à gestão os ciclos acima de 150% do SLO', 'Incluir os ciclos fora do SLO na pauta semanal'], 'Gestor do Ciclo Financeiro'],
        'eventos.slo' => ['Etapas fora do SLO comprometem o cronograma e o orçamento do evento.',
            ['Etapas dependentes de aprovações lentas', 'Planejamento sem folga entre etapas'],
            ['Antecipar o início das etapas com mais atraso', 'Reforçar a alçada e o prazo de aprovação', 'Revisar o SLO das etapas com Marketing e Financeiro'], 'Gestor de Eventos'],
        'eventos.triagem' => ['Triagem lenta atrasa o início do planejamento e reduz o tempo de preparação.',
            ['Briefing incompleto', 'Fila de triagem sem responsável definido'],
            ['Padronizar o briefing de entrada com campos obrigatórios', 'Definir responsável e SLA de 2 dias para a triagem', 'Notificar o gestor quando o prazo se aproximar'], 'Gestor de Eventos'],
        'eventos.devolucoes' => ['Devoluções entre etapas indicam planejamento falho e retrabalho.',
            ['Requisitos mal definidos no briefing', 'Orçamento ou escopo revistos tardiamente'],
            ['Fechar escopo e orçamento antes de avançar de etapa', 'Analisar os motivos de devolução e corrigir na origem', 'Criar checklist de aceite por etapa'], 'Gestor de Eventos'],
        'eventos.fora' => ['Eventos com etapa estourada correm risco de não ficarem prontos na data.',
            ['Etapa ativa sem acompanhamento', 'Dependência de fornecedor sem cobrança'],
            ['Acionar os responsáveis e replanejar a etapa com data de comprometimento', 'Escalar os eventos com data próxima', 'Acompanhar as etapas ativas na reunião semanal'], 'Gestor de Eventos'],
        'eventos.criticos' => ['Data crítica vencida sinaliza decisão pendente que pode inviabilizar o evento.',
            ['Decisão de orçamento ou de local sem responsável', 'Aprovação parada na alçada'],
            ['Levar as datas críticas vencidas ao comitê para decisão imediata', 'Definir o plano B (adiar, reduzir escopo ou cancelar)', 'Registrar o motivo da perda do prazo'], 'Gestor de Eventos e Diretoria'],
        'rh.preenchimento' => ['Vaga aberta por muito tempo sobrecarrega as equipes e atrasa projetos.',
            ['Perfil muito restritivo ou salário abaixo do mercado', 'Funil com poucas fontes de candidatos'],
            ['Revisar o perfil, a faixa salarial e os benefícios com o gestor da vaga', 'Ampliar as fontes de recrutamento e indicações', 'Definir prazos por etapa do funil e acompanhar semanalmente'], 'RH / Atração de talentos'],
        'rh.aprovacao' => ['Aprovação lenta de vagas adia a contratação e o ganho de capacidade.',
            ['Alçada sem prazo definido', 'Vagas submetidas sem justificativa completa'],
            ['Definir prazo de 3 dias para aprovação do gestor e da diretoria', 'Padronizar a justificativa da vaga', 'Enviar lembrete automático ao aprovador'], 'RH e Diretoria'],
        'rh.vagas_atraso' => ['Vagas fora do prazo indicam gargalo de contratação e risco de capacidade.',
            ['Processo seletivo parado em alguma etapa', 'Falta de candidatos qualificados'],
            ['Revisar cada vaga vencida e decidir: reforçar a captação, rever o perfil ou renegociar o prazo', 'Acionar o gestor para agilizar entrevistas', 'Acompanhar o funil semanalmente'], 'RH / Atração de talentos'],
        'rh.ferias' => ['Férias sem decisão geram insegurança no colaborador e dificultam o planejamento da equipe.',
            ['Gestor sem notificação ou ausente', 'Fluxo sem escalonamento'],
            ['Cobrar a decisão do gestor e definir substituto', 'Escalar ao gestor superior o que passa de 5 dias', 'Ativar lembrete automático de pendência'], 'RH e gestores'],
        'rh.admissoes' => ['Admissão com início vencido prejudica a integração e a entrega de acessos e equipamentos.',
            ['Checklist de admissão incompleto', 'Documentação do candidato pendente'],
            ['Concluir o checklist das admissões vencidas e confirmar a data de início', 'Alinhar com TI e Equipamentos a entrega antes do primeiro dia', 'Acompanhar as admissões abertas semanalmente'], 'RH'],
        'equip.antes_inicio' => ['Colaborador sem equipamento no primeiro dia perde produtividade e tem má primeira impressão.',
            ['Solicitação feita em cima da hora', 'Estoque ou compra sem previsão'],
            ['Exigir a solicitação com antecedência mínima de 10 dias', 'Manter estoque mínimo de equipamentos padrão', 'Alertar a entrega a 5 dias do início do colaborador'], 'TI / Controle de Equipamentos'],
        'equip.tempo' => ['Entrega demorada atrasa o início das atividades do colaborador.',
            ['Etapas de configuração e logística sequenciais', 'Dependência de transportadora ou compra'],
            ['Paralelizar configuração e logística', 'Padronizar imagens e kits por cargo', 'Negociar SLA com a transportadora'], 'TI / Controle de Equipamentos'],
        'equip.entregas_atraso' => ['Entregas abertas com início vencido já impactam colaboradores em atividade.',
            ['Entrega parada em alguma etapa', 'Falta de equipamento disponível'],
            ['Priorizar as entregas vencidas e definir data de conclusão', 'Resolver a falta de equipamento com compra emergencial ou remanejamento', 'Informar o gestor do colaborador'], 'TI / Controle de Equipamentos'],
        'equip.devolucoes' => ['Equipamento de desligado sem devolução gera risco patrimonial e de dados.',
            ['Transporte não agendado', 'Falta de contato com o ex-colaborador'],
            ['Agendar a coleta e confirmar o endereço', 'Escalar ao RH os casos sem retorno', 'Registrar a baixa patrimonial e bloquear acessos'], 'TI e RH'],
        'csat.nota' => ['Nota baixa indica atendimento aquém do esperado e risco de perda de confiança.',
            ['Tempo de resposta e de solução elevados', 'Comunicação ao solicitante pouco clara'],
            ['Analisar os comentários das notas 1 e 2 e tratar os temas mais citados', 'Treinar o time em comunicação e acompanhamento do chamado', 'Fazer contato ativo com os solicitantes insatisfeitos'], 'Gestão do atendimento'],
        'csat.positivas' => ['Poucas avaliações positivas indicam experiência irregular do cliente interno.',
            ['Falhas recorrentes no mesmo tipo de chamado', 'Expectativa de prazo não alinhada'],
            ['Identificar as categorias com pior avaliação e criar plano específico', 'Alinhar o prazo esperado no momento da abertura', 'Compartilhar com o time os casos bem avaliados como referência'], 'Gestão do atendimento'],
        'csat.resposta' => ['Baixa taxa de resposta reduz a representatividade da pesquisa.',
            ['Pesquisa longa ou enviada fora de hora', 'Solicitante sem percepção da utilidade'],
            ['Encurtar a pesquisa e reforçar a mensagem de convite', 'Divulgar as melhorias feitas a partir do retorno', 'Testar o envio logo após o fechamento do chamado'], 'Gestão do atendimento'],
        'csat.nps' => ['NPS baixo indica pouca recomendação do atendimento e risco de reputação.',
            ['Detratores concentrados em poucos processos', 'Resolução no primeiro contato abaixo do esperado'],
            ['Tratar os detratores com retorno individual em até 48 horas', 'Atacar as causas mais citadas pelos detratores', 'Acompanhar o NPS mensalmente por categoria'], 'Gestão do atendimento'],
        'enps.score' => ['e-NPS baixo sinaliza clima desfavorável e risco de turnover.',
            ['Sobrecarga e falta de reconhecimento', 'Comunicação e alinhamento de liderança insuficientes'],
            ['Apresentar os resultados ao time e abrir plano de melhoria com participação das equipes', 'Realizar rodas de escuta por grupo e priorizar três ações', 'Treinar lideranças em feedback e reconhecimento'], 'RH e lideranças'],
        'enps.detratores' => ['Alta parcela de detratores aumenta o risco de saída de talentos.',
            ['Insatisfação concentrada em poucos grupos', 'Pontos de dor sem resposta da liderança'],
            ['Identificar os grupos com mais detratores e conduzir escuta qualificada', 'Definir ações visíveis em até 60 dias', 'Repetir a pesquisa em um ciclo para medir o efeito'], 'RH e lideranças'],
    ];

    /** Plano genérico, para indicadores que ainda não têm plano próprio. */
    private const PADRAO = ['O indicador está fora da meta e precisa de acompanhamento da liderança.',
        ['Causa ainda não analisada', 'Processo sem rotina de acompanhamento'],
        ['Investigar a causa-raiz com a área responsável', 'Definir plano com dono e data', 'Acompanhar o indicador no comitê mensal'], 'Gestor da área'];

    /**
     * Plano de um indicador que fugiu da meta.
     *
     * @return array{risco: string, causas: string[], acoes: string[], dono: string, prioridade: string, prazo: int, rotulo: string}
     */
    public static function para(string $code, string $status, bool $vivo): array
    {
        [$risco, $causas, $acoes, $dono] = self::BASE[$code] ?? self::PADRAO;
        $critico = $status === Score::BAD;
        $prazo = $critico ? ($vivo ? 15 : 30) : 60;
        return ['risco' => $risco, 'causas' => $causas, 'acoes' => $acoes, 'dono' => $dono, 'prazo' => $prazo, 'critico' => $critico];
    }

    /** Prioridade pela gravidade: P1 (crítico e pesado), P2 (crítico ou muito pesado) e P3 (atenção). */
    public static function prioridade(string $status, ?float $score, int $peso): string
    {
        if ($status === Score::BAD && ($peso >= 3 || ($score ?? 0) < 25)) {
            return 'P1';
        }
        return $status === Score::BAD || $peso >= 3 ? 'P2' : 'P3';
    }
}
