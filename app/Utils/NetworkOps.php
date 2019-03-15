<?php

namespace App\Utils;

use IPTools\IP;
use IPTools\Network;
use IPTools\Range;

use App\Rede;
use App\Equipamento;

class NetworkOps
{
    // Um método que recebe o ip e cidr da rede e retorna um array com todos ips da mesma
    public function getRange($iprede, $cidr, $include_broadcast = false)
    {
        $ips = [];

        if($include_broadcast)
            $hosts = Network::parse("{$iprede}/{$cidr}");
        else
            $hosts = Network::parse("{$iprede}/{$cidr}")->hosts;

        foreach ($hosts as $ip) {
            array_push($ips, (string)$ip);
        }
        return $ips;
    }

    public function pertenceRede($ip, $iprede, $cidr)
    {   
        return Range::parse("{$iprede}/{$cidr}")->contains(new IP("{$ip}"));
    }

    private function broadcast($iprede, $cidr) {
        $netmask = ~0 << (32-$cidr);
        $rede = ip2long($iprede);
        return long2ip($rede ^ ~$netmask);
    }

    public function nextIpAvailable($ips_alocados, $iprede, $cidr, $gateway)
    {
        /* guarda o gateway e os IPs em forma numérica */
        $ips_numericos = array();
        array_push($ips_numericos, ip2long($gateway));
        foreach($ips_alocados as $ip) {
            array_push($ips_numericos, ip2long($ip));
        }
        sort($ips_numericos);

        /* guarda os limites da rede em forma numérica */
        $min = ip2long($iprede);
        $max = ip2long($this->broadcast($iprede, $cidr));

        for ($ip = $min+1; $ip < $max; $ip++) {
            if (!in_array($ip, $ips_numericos)) {
                return long2ip($ip);
            }
        }

        return false;
    }

    public function isIpAvailable(Rede $rede, $ip)
    {
        $ips_alocados = $rede->equipamentos->pluck('ip')->all();
        if (in_array($ip, $ips_alocados)) {
            return false;
        } else {
            return true;
        }
    }

    public function aloca($rede_id, $ip)
    {
        $danger = '';

        if (empty($ip)) {
            $ip = null;
        }
        if (empty($rede_id)) {
            $rede_id = null;
        }

        if (!is_null($rede_id)) {
            $rede = Rede::find($rede_id);
        } else {
            $rede = null;
        }

        // 1 - aloca ip automaticamente, caso um não seja especificado
        if (is_null($ip) && !is_null($rede)) {
            $ips_alocados = $rede->equipamentos->pluck('ip')->all();

            if (is_null($ips_alocados)) {
                $ips_alocados = [];
            }

            $ip = $this->nextIpAvailable($ips_alocados, $rede->iprede, $rede->cidr, $rede->gateway);

            if ($ip === false) {
                $danger = 'Acabaram os IPs dessa rede, equipamento não alocado!';
                $ip = null;
                $rede_id = null;
            }
        }

        // 2 - quando um ip é especificado e uma rede não
        if (!is_null($ip) && is_null($rede)) {
            $ip = null;
            $danger = 'Rede não especificada, equipamento não alocado';
        }

        // 3 - quando um ip e uma rede são especificados
        if (!is_null($ip) && !is_null($rede)) {
            # ip pertence a rede?
            if (!$this->pertenceRede($ip, $rede->iprede, $rede->cidr)) {
                $danger = 'ip não pertence à rede selecionada, equipamento não alocado';
                $ip = null;
                $rede_id = null;
            } else {
                # verificar se ip está disponível
                if (!$this->isIpAvailable($rede, $ip)) {
                    $danger = 'ip não disponível na rede selecionada, equipamento não alocado';
                    $ip = null;
                    $rede_id = null;
                }
            }
        }
        return ['rede'=>$rede_id, 'ip'=>$ip, 'danger' => $danger];
    }
}
