<?php
// Textos de LGPD da tela de login do hotspot.
//
// Os dois DOCUMENTOS que o cliente abre no portal: Termos de Uso e Política de
// Privacidade. Editáveis por roteador porque a exigência muda de cliente para
// cliente — franquia com jurídico próprio, pousada que não faz contato
// comercial nenhum, estabelecimento que quer outro prazo de retenção.
//
// Texto puro, com as quebras de linha preservadas: o portal escreve com
// textContent e o CSS mantém o formato (white-space: pre-wrap). Nada de HTML —
// quem digita é o comprador, quem renderiza é o aparelho de quem chega.
//
// O padrão muda com o tipo de painel porque o texto do varejo é FALSO numa
// pousada: lá o número não vira contato comercial, ele é a chave que libera o
// acesso de quem fez check-in. Consentimento colhido com descrição errada não
// vale nada.
//
// Carregado pelo inc/util.php.

const LGPD_MAX = 8000;

// Aviso curto que aparece embaixo do botão. Não é editável no painel: é o
// resumo do que os documentos dizem, e sai automático pelo tipo de painel.
function lgpd_aviso(string $modo): string
{
    return $modo === 'hospedagem'
        ? 'Ao prosseguir, você concorda que o número informado no check-in seja usado para liberar e administrar o seu acesso ao Wi-Fi durante a sua hospedagem.'
        : 'Ao prosseguir, você consente que seu número seja registrado como contato do estabelecimento e usado para contato comercial, inclusive por WhatsApp.';
}

function lgpd_termos_padrao(string $modo): string
{
    if ($modo === 'hospedagem') {
        return <<<'TXT'
1. O serviço
Este estabelecimento oferece acesso à internet via Wi-Fi aos seus hóspedes. O acesso é liberado mediante a informação do número de celular cadastrado na recepção no momento do check-in.

2. Aceite
Ao informar seu número e tocar em "Liberar WiFi", você declara que leu e aceitou estes Termos de Uso e a Política de Privacidade.

3. Quem pode usar
O acesso é pessoal e destinado a quem está hospedado. Números não cadastrados na recepção, ou cadastrados para uma hospedagem já encerrada, não liberam a conexão.

4. Duração do acesso
O acesso vale enquanto durar a sua hospedagem e é encerrado automaticamente no horário de saída registrado na recepção.

5. Uso responsável da rede
Você se compromete a utilizar a rede de forma ética e responsável, respeitando as leis vigentes e os direitos de terceiros. É proibido usar a rede para atividades ilegais, difamatórias, invasivas, distribuição de malware ou violação de direitos autorais.

6. Limites de uso e suspensão
O estabelecimento pode aplicar limites de velocidade e pode suspender ou bloquear o acesso de qualquer dispositivo que faça uso indevido da rede ou viole estes termos.

7. Disponibilidade
O serviço é fornecido no estado em que se encontra, sem garantia de velocidade mínima, estabilidade ou disponibilidade ininterrupta da conexão.
TXT;
    }
    return <<<'TXT'
1. O serviço
Este estabelecimento oferece acesso gratuito à internet via Wi-Fi. Em contrapartida, o acesso é liberado mediante o fornecimento do seu número de celular e a exibição de um anúncio antes da conexão.

2. Aceite e consentimento
Ao informar seu número e tocar em "Liberar WiFi", você declara que leu e aceitou estes Termos de Uso e a Política de Privacidade, e consente com a coleta e o uso do seu número de celular para as finalidades descritas na Política — incluindo o recebimento de contatos comerciais do estabelecimento, inclusive por WhatsApp.

3. Veracidade dos dados
Você se compromete a informar um número de celular verdadeiro e de sua titularidade. Números inválidos ou de terceiros podem ter o acesso recusado ou encerrado.

4. Uso responsável da rede
O usuário se compromete a utilizar a rede de forma ética e responsável, respeitando as leis vigentes e os direitos de terceiros. É proibido usar a rede para atividades ilegais, difamatórias, invasivas, distribuição de malware ou violação de direitos autorais.

5. Limites de uso e suspensão
O estabelecimento pode aplicar limites de tempo de uso diário e de velocidade por usuário, e pode suspender ou bloquear o acesso de qualquer dispositivo que faça uso indevido da rede ou viole estes termos.

6. Disponibilidade
O serviço é fornecido no estado em que se encontra, sem garantia de velocidade mínima, estabilidade ou disponibilidade ininterrupta da conexão.
TXT;
}

function lgpd_privacidade_padrao(string $modo): string
{
    if ($modo === 'hospedagem') {
        return <<<'TXT'
1. Quais dados coletamos
Ao usar este Wi-Fi, coletamos: o número de celular informado no check-in, o endereço MAC e o tipo/sistema do seu aparelho, o endereço IP atribuído e as datas, horários e duração das suas conexões.

2. Para que usamos (finalidades)
(a) conferir se o número informado corresponde a uma hospedagem ativa e liberar o seu acesso ao Wi-Fi; (b) administrar esse acesso enquanto durar a sua estadia, inclusive aplicar limites de velocidade; (c) segurança da rede e cumprimento de obrigações legais. O seu número não é usado para contato comercial nem para publicidade.

3. Base legal (LGPD)
O tratamento é necessário para a execução da hospedagem contratada com você (art. 7º, V, da Lei nº 13.709/2018 — LGPD) e para o cumprimento de obrigações legais do estabelecimento. Registros de conexão podem ser mantidos por exigência legal.

4. Quem trata os dados
O controlador dos dados é o estabelecimento onde você está hospedado. A plataforma Captive Data atua como operadora, armazenando os dados de forma segura por conta e ordem do estabelecimento. Seus dados não são vendidos nem compartilhados com terceiros estranhos a esta operação, salvo obrigação legal ou ordem de autoridade competente.

5. Por quanto tempo guardamos
Os dados de conexão são mantidos por até 6 (seis) meses a contar da sua última conexão e depois são excluídos, salvo prazo maior exigido por lei.

6. Seus direitos
Nos termos do art. 18 da LGPD, você pode, a qualquer momento: confirmar a existência de tratamento, acessar e corrigir seus dados e solicitar a exclusão. Para exercer esses direitos, fale diretamente com a recepção do estabelecimento.

7. Segurança
Adotamos medidas técnicas e administrativas para proteger seus dados contra acessos não autorizados, perda ou alteração, em conformidade com a LGPD e demais legislações brasileiras aplicáveis.
TXT;
    }
    return <<<'TXT'
1. Quais dados coletamos
Ao usar este Wi-Fi, coletamos: o número de celular que você informa, o endereço MAC e o tipo/sistema do seu aparelho, o endereço IP atribuído e as datas, horários e duração das suas conexões.

2. Para que usamos (finalidades)
(a) liberar e administrar o seu acesso ao Wi-Fi, inclusive aplicar limites de uso; (b) registrar sua visita como contato comercial (lead) do estabelecimento; (c) permitir que o estabelecimento entre em contato com você para fins comerciais e promocionais, inclusive por WhatsApp, telefone ou SMS; (d) segurança da rede e cumprimento de obrigações legais.

3. Base legal e consentimento (LGPD)
O tratamento é feito com fundamento no seu consentimento (art. 7º, I, da Lei nº 13.709/2018 — LGPD), manifestado ao informar seu número e tocar em "Liberar WiFi". Registros de conexão também podem ser mantidos para cumprimento de obrigação legal.

4. Quem trata os dados
O controlador dos dados é o estabelecimento que oferece este Wi-Fi. A plataforma Captive Data atua como operadora, armazenando os dados de forma segura por conta e ordem do estabelecimento. Seus dados não são vendidos nem compartilhados com terceiros estranhos a esta operação, salvo obrigação legal ou ordem de autoridade competente.

5. Por quanto tempo guardamos
Os dados de contato e de conexão são mantidos por até 6 (seis) meses a contar da sua última conexão e depois são excluídos, salvo prazo maior exigido por lei.

6. Seus direitos
Nos termos do art. 18 da LGPD, você pode, a qualquer momento: confirmar a existência de tratamento, acessar e corrigir seus dados, solicitar a exclusão, e revogar o consentimento — inclusive pedir para não receber mais contatos (opt-out). Para exercer esses direitos, fale diretamente com o estabelecimento onde você usou o Wi-Fi.

7. Segurança
Adotamos medidas técnicas e administrativas para proteger seus dados contra acessos não autorizados, perda ou alteração, em conformidade com a LGPD e demais legislações brasileiras aplicáveis.
TXT;
}

function lgpd_padrao(string $modo): array
{
    return [
        'aviso'       => lgpd_aviso($modo),
        'termos'      => lgpd_termos_padrao($modo),
        'privacidade' => lgpd_privacidade_padrao($modo),
    ];
}

function lgpd_file(string $roteador): string
{
    return anuncio_base($roteador) . '.lgpd';
}

// Documentos em uso: os que o comprador escreveu, ou os padrões do tipo de painel.
function lgpd_get(string $roteador): array
{
    $def = lgpd_padrao(roteador_modo($roteador));
    if (trim($roteador) === '') {
        return $def;
    }
    $j = json_decode((string) @file_get_contents(lgpd_file($roteador)), true);
    if (!is_array($j)) {
        return $def;
    }
    $out = $def;
    foreach (['termos', 'privacidade'] as $k) {
        // Vazio = "usar o padrão". É assim que o comprador desfaz a alteração
        // dele sem precisar lembrar o texto original.
        if (isset($j[$k]) && is_string($j[$k]) && trim($j[$k]) !== '') {
            $out[$k] = lgpd_limpa($j[$k]);
        }
    }
    return $out;
}

// Texto puro, com as quebras preservadas.
function lgpd_limpa(string $t): string
{
    $t = strip_tags($t);
    $t = str_replace(["\r\n", "\r"], "\n", $t);
    // Três ou mais linhas em branco viram uma: espaçamento é trabalho do CSS.
    $t = (string) preg_replace("/\n{3,}/", "\n\n", $t);
    return trim(mb_substr($t, 0, LGPD_MAX));
}

function lgpd_set(string $roteador, string $termos, string $privacidade): bool
{
    if (trim($roteador) === '') {
        return false;
    }
    $def = lgpd_padrao(roteador_modo($roteador));
    $j   = [];
    // Igual ao padrão? Não grava. Assim o arquivo só existe quando há mesmo uma
    // escolha, e melhorar o padrão depois alcança quem nunca personalizou.
    foreach (['termos' => $termos, 'privacidade' => $privacidade] as $k => $v) {
        if (trim($v) !== '' && lgpd_limpa($v) !== $def[$k]) {
            $j[$k] = lgpd_limpa($v);
        }
    }
    if (!$j) {
        @unlink(lgpd_file($roteador));
        return true;
    }
    return @file_put_contents(lgpd_file($roteador), json_encode($j, JSON_UNESCAPED_UNICODE)) !== false;
}
