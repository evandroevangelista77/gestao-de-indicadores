<?php

namespace GlpiPlugin\Indicadores;

use CommonDBTM;
use Session;

/**
 * Meta e limite de cada indicador. O catálogo nasce dos coletores; aqui a equipe ajusta a meta, o limite
 * (onde o indicador passa de amarelo para vermelho), o peso na nota da área e se o indicador conta.
 */
class Meta extends CommonDBTM
{
    public static string $rightname = 'plugin_indicadores';

    public static function getTypeName($nb = 0): string
    {
        return $nb > 1 ? 'Metas e limites' : 'Meta e limite';
    }

    /** Cria o que falta no catálogo e atualiza nome, unidade e direção do que já existe (sem mexer nas metas editadas). */
    public static function sync(): void
    {
        global $DB;
        $existing = [];
        foreach ($DB->request(['SELECT' => ['id', 'code'], 'FROM' => self::getTable()]) as $r) {
            $existing[$r['code']] = (int) $r['id'];
        }
        $pos = 0;
        foreach (Motor::coletores() as $area => $c) {
            foreach ($c->indicadores() as $code => $def) {
                $pos += 10;
                $static = ['area' => $area, 'name' => $def['name'], 'unit' => $def['unit'], 'dir' => $def['dir'], 'vivo' => $def['vivo'] ? 1 : 0, 'dica' => $def['dica'], 'position' => $pos];
                if (isset($existing[$code])) {
                    $DB->update(self::getTable(), $static, ['id' => $existing[$code]]);
                } else {
                    $DB->insert(self::getTable(), $static + ['code' => $code, 'meta' => $def['meta'], 'limite' => $def['limite'], 'peso' => $def['peso'], 'is_active' => 1, 'date_mod' => date('Y-m-d H:i:s')]);
                }
            }
        }
        Motor::esquecerCatalogo();
    }

    public function prepareInputForUpdate($input)
    {
        foreach (['meta', 'limite'] as $f) {
            if (array_key_exists($f, $input)) {
                $v = str_replace(',', '.', trim((string) $input[$f]));
                if ($v === '') {
                    $input[$f] = null;
                } elseif (!is_numeric($v)) {
                    Session::addMessageAfterRedirect(__('Meta e limite precisam ser números.'), false, ERROR);
                    return false;
                } else {
                    $input[$f] = (float) $v;
                }
            }
        }
        if (isset($input['peso'])) {
            $input['peso'] = max(0, min(10, (int) $input['peso']));
        }
        if (array_key_exists('is_active', $input)) {
            $input['is_active'] = empty($input['is_active']) ? 0 : 1;
        }
        $input['date_mod'] = date('Y-m-d H:i:s');
        return parent::prepareInputForUpdate($input);
    }

    /** Indicadores gravados, na ordem do catálogo. */
    public static function todos(): array
    {
        global $DB;
        return iterator_to_array($DB->request(['FROM' => self::getTable(), 'ORDER' => ['position', 'id']]), false);
    }
}
