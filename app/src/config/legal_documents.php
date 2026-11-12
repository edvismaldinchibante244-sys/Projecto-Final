<?php

require_once __DIR__ . '/personal_data.php';
require_once __DIR__ . '/email_helper.php';

if (!function_exists('legal_env')) {
    function legal_env(string $key, string $default = ''): string
    {
        saas_email_load_local_env();

        $value = getenv($key);
        if ($value === false || trim((string)$value) === '') {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? $default;
        }

        return trim((string)$value);
    }
}

if (!function_exists('legal_config')) {
    function legal_config(): array
    {
        return [
            'entity_name' => legal_env('LEGAL_ENTITY_NAME'),
            'trade_name' => legal_env('LEGAL_TRADE_NAME', 'Nivora Restaurant'),
            'nuit' => legal_env('LEGAL_NUIT'),
            'address' => legal_env('LEGAL_ADDRESS'),
            'email' => legal_env('LEGAL_EMAIL'),
            'phone' => legal_env('LEGAL_PHONE'),
            'jurisdiction' => legal_env('LEGAL_JURISDICTION', 'Moçambique'),
            'effective_date' => legal_env('LEGAL_EFFECTIVE_DATE', '2026-11-03'),
            'privacy_version' => legal_env('LEGAL_PRIVACY_VERSION', '2026.11.03'),
            'terms_version' => legal_env('LEGAL_TERMS_VERSION', '2026.11.12'),
            'cookie_version' => legal_env('LEGAL_COOKIE_VERSION', '2026.11.03'),
        ];
    }
}

if (!function_exists('legal_identity_missing_fields')) {
    function legal_identity_missing_fields(): array
    {
        $config = legal_config();
        $required = [
            'entity_name' => 'nome legal da empresa',
            'nuit' => 'NUIT',
            'address' => 'endereco legal',
            'email' => 'email juridico/privacidade',
            'phone' => 'contacto telefonico',
        ];
        $missing = [];

        foreach ($required as $key => $label) {
            $value = strtolower(trim((string)($config[$key] ?? '')));
            $isPlaceholder = str_contains($value, 'nome legal')
                || str_contains($value, 'seudominio')
                || str_contains($value, 'nuit da empresa')
                || str_contains($value, 'endereco legal completo')
                || str_contains($value, '000000000');
            if ($value === '' || $isPlaceholder) {
                $missing[] = $label;
            }
        }

        if (($config['email'] ?? '') !== '' && !filter_var($config['email'], FILTER_VALIDATE_EMAIL)) {
            $missing[] = 'email juridico valido';
        }

        return array_values(array_unique($missing));
    }
}

if (!function_exists('legal_identity_ready')) {
    function legal_identity_ready(): bool
    {
        return legal_identity_missing_fields() === [];
    }
}

if (!function_exists('legal_document')) {
    function legal_document(string $key): array
    {
        $config = legal_config();
        $identity = $config['entity_name'] !== '' ? $config['entity_name'] : $config['trade_name'];
        $contact = $config['email'] !== '' ? $config['email'] : 'contacto juridico por configurar';

        $complianceTxt = legal_env('LEGAL_COMPLIANCE_OFFICER', '') !== '' ? legal_env('LEGAL_COMPLIANCE_OFFICER') . ' (' . $contact . ')' : 'contactável através de ' . $contact;
        $dataCountry = legal_env('LEGAL_DATA_COUNTRY', 'país indicado mediante pedido');
        $nuitTxt = $config['nuit'] !== '' ? ', NUIT ' . $config['nuit'] : '';
        $addressTxt = $config['address'] !== '' ? ', com sede em ' . $config['address'] : '';
        $hosting = legal_env('LEGAL_HOSTING_PROVIDER', 'fornecedor de alojamento indicado mediante pedido');
        $emailProvider = legal_env('LEGAL_EMAIL_PROVIDER', 'fornecedor de email indicado mediante pedido');
        $foro = legal_env('LEGAL_FORO', 'Tribunal Judicial da Cidade de Maputo');
        $documents = [
            'privacy' => [
                'title' => 'Política de Privacidade',
                'version' => $config['privacy_version'],
                'summary' => 'Explica que dados pessoais a Nivora Restaurant trata, porquê, com quem os partilha, durante quanto tempo os guarda e como exercer os seus direitos.',
                'sections' => [
                    ['title' => '1. Quem somos e como nos contactar', 'paragraphs' => [
                        'A plataforma Nivora Restaurant é disponibilizada por ' . $identity . $nuitTxt . $addressTxt . '. Para qualquer assunto de privacidade, contacte ' . $contact . '. A pessoa designada como responsável pela conformidade no tratamento de dados, nos termos da Lei n.º 3/2017, é ' . $complianceTxt . '.',
                        'Esta política aplica-se a quem usa a plataforma: donos e trabalhadores de restaurantes, clientes que pedem ou reservam através dela, e visitantes do site.',
                    ]],
                    ['title' => '2. Dois papéis diferentes', 'paragraphs' => [
                        'Dados da própria plataforma (contas, facturação dos planos, segurança e suporte): a Nivora Restaurant decide como são tratados e é responsável por eles.',
                        'Dados que cada restaurante regista na plataforma (os seus clientes, trabalhadores, pedidos, reservas e vendas): o restaurante é o responsável. A Nivora Restaurant trata-os apenas por conta e segundo as instruções do restaurante, nos termos do Acordo de Tratamento de Dados incluído nos Termos de Uso, e não os usa para fins próprios.',
                        'Se é cliente de um restaurante e quer saber o que esse restaurante faz com os seus dados, contacte primeiro o restaurante. Também nos pode contactar e encaminharemos o pedido.',
                    ]],
                    ['title' => '3. Que dados tratamos', 'items' => [
                        'Conta e restaurante: nome, email, telefone, endereço, NUIT, função, perfil de acesso, palavra-passe (guardada apenas em forma cifrada irreversível) e PIN de desbloqueio (idem).',
                        'Trabalhadores: nome, função, turnos, horários de entrada e saída, e registo das acções feitas no sistema (por exemplo, quem cancelou um item ou autorizou um desconto).',
                        'Clientes dos restaurantes: nome, telefone e, quando fornecidos, endereço de entrega, histórico de pedidos e reservas, e pontos de fidelização.',
                        'Facturação dos planos: plano escolhido, valores, método de pagamento e comprovativos enviados. Não guardamos dados completos de cartões bancários.',
                        'Dados técnicos e de segurança: endereço IP, navegador, dispositivo, identificadores de sessão, terminal usado e registos de acesso e de auditoria.',
                        'Suporte e pedidos de direitos: mensagens, anexos e dados necessários para confirmar a identidade de quem pede.',
                    ]],
                    ['title' => '4. Para que usamos os dados e com que fundamento', 'items' => [
                        'Prestar o serviço contratado: contas, pedidos, mesas, cozinha, caixa, stock, relatórios e suporte (execução do contrato).',
                        'Gerir planos, pagamentos, renovações e cancelamentos (execução do contrato).',
                        'Proteger contas e dados: verificação em dois passos, detecção de acessos suspeitos, auditoria e resposta a incidentes (interesse legítimo e dever legal de segurança).',
                        'Cumprir obrigações legais, fiscais e contabilísticas e responder a ordens válidas de autoridades (obrigação legal).',
                        'Enviar avisos de serviço (por exemplo, vencimento do plano ou alterações destes textos). Comunicações promocionais só são enviadas com o seu consentimento, que pode retirar a qualquer momento.',
                        'Não vendemos dados pessoais, não os usamos para publicidade de terceiros e não tomamos decisões automáticas com efeitos jurídicos sobre as pessoas.',
                    ]],
                    ['title' => '5. Com quem partilhamos', 'paragraphs' => [
                        'Só partilhamos dados quando é necessário para prestar o serviço, com fornecedores que se comprometem por escrito a mantê-los confidenciais e seguros:',
                    ], 'items' => [
                        'Alojamento e base de dados: ' . $hosting . '.',
                        'Envio de emails (códigos de verificação, recuperação de senha, avisos): ' . $emailProvider . '.',
                        'Bibliotecas e fontes do site (por exemplo, jsDelivr, Cloudflare e Google Fonts), que recebem apenas dados técnicos como o endereço IP.',
                        'Autoridades públicas, quando a lei o exija ou mediante ordem válida.',
                        'Um eventual comprador ou sucessor do negócio, que fica obrigado a esta política.',
                    ]],
                    ['title' => '6. Dados fora de Moçambique', 'paragraphs' => [
                        'Alguns fornecedores podem guardar dados em centros de dados fora de Moçambique. Nesses casos escolhemos fornecedores com medidas de segurança reconhecidas e exigimos por contrato confidencialidade, segurança e uso apenas para o serviço prestado. Os dados da plataforma estão alojados em: ' . $dataCountry . '. Nos termos da Lei n.º 3/2017, os dados não são transmitidos a terceiros fora dos casos previstos na lei, por decisão judicial ou nesta política.',
                    ]],
                    ['title' => '7. Durante quanto tempo guardamos', 'items' => [
                        'Dados da conta e do restaurante: enquanto o contrato estiver activo.',
                        'Após o cancelamento: o restaurante tem 30 dias para exportar os seus dados; depois disso são apagados no prazo de 90 dias, salvo o que a lei obrigue a conservar.',
                        'Documentos de facturação e registos contabilísticos e fiscais: pelo prazo legal de conservação aplicável.',
                        'Registos de acesso e de segurança: até 12 meses, salvo se forem necessários para investigar um incidente ou defender direitos.',
                        'Cópias de segurança: são substituídas automaticamente em ciclos de até 35 dias; um dado apagado desaparece das cópias no fim desse ciclo.',
                        'Pedidos de suporte e de direitos: até 3 anos após a resposta.',
                    ]],
                    ['title' => '8. Como protegemos os dados', 'paragraphs' => [
                        'Usamos ligação cifrada (HTTPS), palavras-passe e PIN com cifra irreversível, cifragem de emails e telefones na base de dados, verificação em dois passos, perfis de acesso por função, autorização do gerente para acções sensíveis, registo de auditoria, cópias de segurança e monitorização de ataques.',
                        'Nenhum sistema é totalmente invulnerável. Se ocorrer um incidente que afecte dados pessoais, investigamos de imediato, tomamos medidas para o conter e avisamos os restaurantes afetados sem demora injustificada, em princípio nas 72 horas seguintes a termos conhecimento, bem como as autoridades quando a lei o exija.',
                    ]],
                    ['title' => '9. Os seus direitos', 'paragraphs' => [
                        'Pode pedir acesso aos seus dados, a sua rectificação, apagamento, limitação ou portabilidade, e opor-se a certos tratamentos. Pode também retirar um consentimento dado, sem afectar o que foi feito antes.',
                        'Respondemos no prazo de 30 dias. Podemos pedir prova de identidade antes de responder. Alguns pedidos podem ser recusados ou limitados quando a lei nos obrigue a conservar os dados ou quando afectem direitos de outras pessoas; nesse caso explicamos porquê.',
                        'Se não ficar satisfeito com a nossa resposta, pode recorrer às autoridades ou aos tribunais competentes.',
                    ], 'link' => ['href' => 'direitos_dados.php', 'label' => 'Enviar pedido sobre os meus dados']],
                    ['title' => '10. Menores', 'paragraphs' => [
                        'A plataforma é uma ferramenta profissional e não se destina a menores de 18 anos. Os restaurantes não devem criar contas de acesso para menores.',
                    ]],
                    ['title' => '11. Alterações e lei aplicável', 'paragraphs' => [
                        'Podemos actualizar esta política, por exemplo quando entrar em vigor legislação de protecção de dados pessoais em Moçambique. A versão e a data de vigência no topo identificam o texto aplicável. Alterações relevantes são comunicadas com pelo menos 30 dias de antecedência, salvo quando a lei exija aplicação imediata.',
                        'Esta política rege-se pela lei de ' . $config['jurisdiction'] . ', designadamente a Constituição da República de Moçambique, a Lei n.º 3/2017, de 9 de Janeiro (Lei das Transacções Electrónicas), em especial o seu Capítulo IX, artigos 63 a 65, e a Lei n.º 13/2026 (Segurança Cibernética).',
                        'Moçambique ainda não dispõe de lei geral de protecção de dados pessoais em vigor. A Proposta de Lei de Protecção de Dados Pessoais foi aprovada pelo Conselho de Ministros a 3 de Março de 2026 e aguarda apreciação pela Assembleia da República. Comprometemo-nos a rever esta política e os Termos de Uso logo que essa lei entre em vigor, e a adoptar desde já os princípios nela previstos: finalidade determinada, consentimento livre, específico e informado, segurança e direitos de acesso, rectificação, apagamento e oposição.',
                    ]],
                ],
            ],
            'terms' => [
                'title' => 'Termos de Uso',
                'version' => $config['terms_version'],
                'summary' => 'Contrato entre a Nivora Restaurant e o restaurante cliente: acesso, planos, pagamentos, dados, responsabilidades e encerramento.',
                'sections' => [
                    ['title' => '1. Partes e aceitação', 'paragraphs' => [
                        'Estes Termos são um contrato entre ' . $identity . $nuitTxt . $addressTxt . ' ("Nivora Restaurant") e o restaurante que cria a conta ou contrata um plano ("Cliente").',
                        'Quem aceita estes Termos declara ter pelo menos 18 anos e poderes para obrigar o Cliente. A aceitação é feita electronicamente e fica registada com data, versão e identificação do texto aceite, com o mesmo valor de um documento assinado, nos termos da Lei n.º 3/2017, de 9 de Janeiro.',
                        'Os trabalhadores a quem o Cliente cria contas devem cumprir estes Termos na parte que lhes respeita; o Cliente é responsável por lhes dar a conhecer.',
                    ]],
                    ['title' => '2. O serviço', 'paragraphs' => [
                        'A Nivora Restaurant é um sistema de gestão de restaurantes acessível pela internet: pedidos, mesas, reservas, cozinha, caixa, stock e ingredientes, relatórios e cardápio digital por código QR. As funcionalidades e limites de cada plano são os indicados no sistema no momento da contratação.',
                        'Podemos melhorar ou alterar funcionalidades. Se uma alteração retirar uma funcionalidade essencial de um plano já pago, o Cliente pode cancelar e receber o reembolso proporcional do período não usado.',
                    ]],
                    ['title' => '3. Contas e segurança', 'items' => [
                        'As informações fornecidas devem ser verdadeiras e mantidas actualizadas.',
                        'Cada pessoa usa apenas a sua própria conta e PIN e mantém-nos secretos. Partilhar contas é proibido.',
                        'O Cliente atribui os perfis adequados a cada trabalhador e retira o acesso a quem deixar de trabalhar consigo.',
                        'O Cliente avisa-nos de imediato de qualquer acesso suspeito. Respondemos pelas acções feitas na conta apenas quando resultem de falha de segurança nossa.',
                    ]],
                    ['title' => '4. Planos, preços e pagamento', 'items' => [
                        'O preço, a duração (mensal, trimestral ou anual) e o método de pagamento são os indicados no sistema no momento da contratação. Os preços incluem os impostos aplicáveis, salvo indicação em contrário.',
                        'Quando o pagamento é feito por transferência ou carteira móvel com envio de comprovativo, o plano é activado depois de confirmarmos a recepção do valor.',
                        'Antes do fim do período, avisamos o Cliente da renovação. Nos 7 dias seguintes ao vencimento sem pagamento, a conta fica em modo limitado: é possível consultar e exportar os dados, mas não registar vendas. Depois desse prazo, o acesso fica bloqueado, excepto a exportação de dados pelo administrador em «Meus dados e plano», até à regularização.',
                        'Alterações de preço só se aplicam ao período seguinte e são comunicadas com pelo menos 30 dias de antecedência.',
                        'Subir de plano tem efeito imediato. Descer de plano tem efeito no período seguinte e não apaga dados, mas pode limitar funcionalidades.',
                        'Oferta de boas-vindas: no primeiro pagamento de cada Cliente, o restaurante usa durante 14 dias o plano imediatamente acima do que pagou, sem custo. Quem paga o plano mais alto recebe, em vez disso, 14 dias extra de validade. A oferta é atribuída uma só vez por restaurante e por titular, não se aplica a períodos de experiência e não se acumula com outras ofertas.',
                        'Terminados os 14 dias, o restaurante volta ao plano que pagou. Nada é apagado: os registos criados durante a oferta continuam acessíveis e editáveis; fica apenas suspensa a criação de novos registos nas categorias em que o plano pago já esteja no limite, até o Cliente voltar ao limite ou subir de plano.',
                    ]],
                    ['title' => '5. Cancelamento e reembolsos', 'paragraphs' => [
                        'O Cliente pode cancelar a qualquer momento em «Meus dados e plano» ou por email. O cancelamento produz efeitos no fim do período já pago, sem reembolso desse período, salvo nos casos previstos nas secções 2 e 8 ou quando a lei imperativamente o imponha.',
                        'Devolução do primeiro pagamento: um Cliente novo que cancele nos primeiros 14 dias após o primeiro pagamento recebe a devolução integral desse pagamento, pedida em «Meus dados e plano» ou por email, processada no prazo de 14 dias pelo mesmo meio de pagamento. A devolução aplica-se uma só vez por restaurante e por titular e não se aplica a renovações nem a mudanças de plano posteriores.',
                        'Após o encerramento, o administrador do Cliente continua a poder entrar, apenas para exportar os dados, durante 30 dias. Os dados são apagados definitivamente nos 90 dias seguintes, com aviso por email 14 dias antes, salvo o que a lei obrigue a conservar; no momento da eliminação é emitida e enviada ao Cliente uma declaração de eliminação.',
                    ]],
                    ['title' => '6. Obrigações do Cliente', 'items' => [
                        'Usar a plataforma de forma lícita e respeitar os direitos dos seus clientes, trabalhadores e fornecedores.',
                        'Informar os seus clientes e trabalhadores sobre o uso dos seus dados e obter os consentimentos necessários, em especial para comunicações promocionais.',
                        'Cumprir as suas obrigações fiscais. Os talões emitidos pela plataforma são documentos internos de controlo e não substituem a factura ou outro documento fiscal exigido por lei, salvo se a plataforma estiver expressamente certificada para esse fim.',
                        'Conferir preços, stock, pagamentos e relatórios antes de tomar decisões comerciais.',
                        'Manter equipamentos, energia e internet adequados e um procedimento alternativo (por exemplo, registo em papel) para falhas prolongadas.',
                    ]],
                    ['title' => '7. Acordo de Tratamento de Dados', 'items' => [
                        'Tratar os dados apenas para prestar o serviço e segundo as instruções do Cliente, que são estes Termos e o uso que o Cliente faz do sistema.',
                        'Garantir que quem tem acesso aos dados está obrigado a sigilo.',
                        'Aplicar as medidas de segurança descritas na Política de Privacidade.',
                        'Recorrer apenas aos subcontratados indicados na Política de Privacidade, que o Cliente autoriza por escrito ao aceitar estes Termos, impondo-lhes por contrato as mesmas obrigações; avisar o Cliente com 30 dias de antecedência (prazo contratual) antes de acrescentar um novo, podendo o Cliente opor-se e cancelar.',
                        'Ajudar o Cliente a responder a pedidos dos titulares dos dados.',
                        'Avisar o Cliente sem demora injustificada e, em qualquer caso, no prazo contratual de 72 horas, de qualquer incidente que afecte os seus dados, com a informação disponível.',
                        'No fim do contrato, permitir a exportação e depois apagar os dados, nos prazos da secção 5, entregando ao Cliente que o peça uma declaração escrita da eliminação.',
                        'Manter um responsável pela conformidade no tratamento de dados, identificado na Política de Privacidade, como ponto de contacto do Cliente.',
                        'Disponibilizar, mediante pedido razoável, a informação necessária para demonstrar o cumprimento destas obrigações.',
                        'Rever este acordo quando entrar em vigor a lei moçambicana de protecção de dados pessoais, adaptando-o às obrigações que ela venha a impor.',
                    ], 'paragraphs' => [
                        'Quanto aos dados pessoais que o Cliente regista na plataforma, o Cliente é o responsável pelo tratamento e a Nivora Restaurant actua como subcontratante. Nessa qualidade, e em cumprimento dos artigos 63 a 65 da Lei n.º 3/2017, a Nivora Restaurant compromete-se a:',
                    ]],
                    ['title' => '8. Disponibilidade e suporte', 'paragraphs' => [
                        'Fazemos esforços razoáveis para manter o serviço disponível 24 horas por dia e avisamos com antecedência as manutenções planeadas, que fazemos de preferência fora das horas de maior movimento. O sistema continua a registar pedidos sem internet no dispositivo e sincroniza-os quando a ligação volta.',
                        'Não garantimos um serviço sem interrupções: falhas de internet, energia, fornecedores ou casos de força maior podem afectá-lo. Se o serviço estiver indisponível por causa nossa por mais de 24 horas seguidas, o Cliente tem direito a um crédito proporcional ao tempo de paragem no período seguinte.',
                        'O suporte é prestado através da área de Suporte do sistema e do contacto indicado, em dias úteis, com primeira resposta em princípio no prazo de 1 dia útil.',
                    ]],
                    ['title' => '9. Dados do Cliente e propriedade intelectual', 'paragraphs' => [
                        'Os dados que o Cliente introduz pertencem ao Cliente. Podemos usar estatísticas agregadas e anónimas, que não identifiquem o Cliente nem qualquer pessoa, para melhorar o serviço.',
                        'O software, a marca, o design e a documentação da Nivora Restaurant são nossos ou dos nossos licenciadores. O Cliente recebe um direito de uso não exclusivo e intransmissível durante o contrato. É proibido copiar, revender, descompilar ou criar produtos derivados.',
                    ]],
                    ['title' => '10. Utilizações proibidas e suspensão', 'paragraphs' => [
                        'É proibido explorar vulnerabilidades, contornar controlos de segurança, aceder a dados de outros clientes, introduzir código malicioso, praticar fraude ou usar a plataforma contra a lei, incluindo a Lei n.º 14/2026 (Crimes Cibernéticos).',
                        'Podemos suspender o acesso, total ou parcialmente, para conter um risco de segurança, por falta de pagamento nos termos da secção 4, por violação grave destes Termos ou por ordem de uma autoridade. Avisamos o Cliente antes, salvo urgência, e a suspensão dura apenas o tempo necessário.',
                    ]],
                    ['title' => '11. Responsabilidade', 'paragraphs' => [
                        'Cada parte responde pelos danos que causar por incumprimento destes Termos.',
                        'A nossa responsabilidade total, por todos os factos num período de 12 meses, fica limitada ao valor que o Cliente nos pagou nesses 12 meses. Não respondemos por lucros cessantes ou perda de negócio indirectos, nem por decisões tomadas pelo Cliente com base em dados que ele próprio introduziu.',
                        'Estes limites não se aplicam em caso de dolo ou culpa grave, nem a danos causados à vida ou à integridade física, nem aos direitos que a lei não permita excluir ou limitar.',
                    ]],
                    ['title' => '12. Alterações aos Termos', 'paragraphs' => [
                        'Podemos alterar estes Termos por razões legais, técnicas ou comerciais. Alterações relevantes são comunicadas com pelo menos 30 dias de antecedência, por email e no sistema. Se o Cliente não concordar, pode cancelar antes da data de efeito e, se tiver pago um período mais longo, recebe o reembolso proporcional. Continuar a usar o serviço após essa data vale como aceitação.',
                    ]],
                    ['title' => '13. Lei aplicável e litígios', 'paragraphs' => [
                        'Estes Termos regem-se pela lei de ' . $config['jurisdiction'] . '. Em caso de desacordo, as partes procuram primeiro resolvê-lo de boa-fé durante 30 dias após aviso escrito. Não havendo acordo, é competente o ' . $foro . ', sem prejuízo das regras obrigatórias de competência.',
                        'Se alguma cláusula for considerada inválida, as restantes mantêm-se. Contacto contratual: ' . $contact . '.',
                    ]],
                ],
            ],
            'cookies' => [
                'title' => 'Política de Cookies',
                'version' => $config['cookie_version'],
                'summary' => 'Descreve os cookies e o armazenamento no navegador usados para sessão, segurança, funcionamento sem internet e preferências.',
                'sections' => [
                    ['title' => '1. O que são cookies', 'paragraphs' => [
                        'Cookies e tecnologias semelhantes guardam pequenos ficheiros ou identificadores no seu navegador. Servem para manter a sessão iniciada, proteger formulários, permitir o uso sem internet e recordar preferências.',
                    ]],
                    ['title' => '2. O que usamos', 'items' => [
                        'Sessão e autenticação (essenciais): mantêm a sessão iniciada e terminam quando sai ou após inatividade.',
                        'Segurança (essenciais): protecção contra pedidos falsificados e abuso, verificação em dois passos e identificação do terminal de confiança do restaurante, que dura até ser revogado pelo administrador.',
                        'Funcionamento sem internet (essenciais quando activo): guardam pedidos no dispositivo até haver ligação para os enviar.',
                        'Preferências (funcionais): por exemplo, recordar que este aviso foi lido ou o estado do menu lateral.',
                        'Por serem necessários para o serviço pedido, estes cookies não dependem de consentimento. Não usamos cookies de publicidade nem de análise de comportamento de terceiros.',
                    ]],
                    ['title' => '3. Serviços externos', 'paragraphs' => [
                        'Para carregar fontes e bibliotecas, o site liga-se a serviços externos (por exemplo, jsDelivr, Cloudflare e Google Fonts), que recebem dados técnicos como o endereço IP e o tipo de navegador, segundo as suas próprias políticas. Se no futuro usarmos cookies não essenciais, pediremos o seu consentimento antes.',
                    ]],
                    ['title' => '4. Como controlar', 'paragraphs' => [
                        'Pode apagar ou bloquear cookies nas definições do navegador. Se bloquear os essenciais, não conseguirá iniciar sessão nem usar o sistema. Apagar os dados do site num terminal de confiança obriga o administrador a marcá-lo de novo.',
                    ]],
                    ['title' => '5. Alterações e contacto', 'paragraphs' => [
                        'A versão e a data de vigência identificam esta política. Para dúvidas, contacte ' . $contact . '.',
                    ]],
                ],
            ],
        ];
        if (!isset($documents[$key])) {
            throw new InvalidArgumentException('Documento legal desconhecido.');
        }

        return $documents[$key];
    }
}

if (!function_exists('legal_document_hash')) {
    function legal_document_hash(string $key): string
    {
        return hash('sha256', json_encode(legal_document($key), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}

if (!function_exists('legal_request_hash')) {
    function legal_request_hash(string $value): string
    {
        $secret = legal_env('APP_KEY');
        if ($secret === '') {
            $secret = legal_env('PII_HASH_KEY');
        }
        if ($secret === '') {
            throw new RuntimeException('APP_KEY ou PII_HASH_KEY deve estar configurada.');
        }

        return hash_hmac('sha256', $value, $secret);
    }
}

if (!function_exists('legal_ensure_schema')) {
    function legal_ensure_schema(PDO $db): void
    {
        static $done = false;
        if ($done) {
            return;
        }

        $db->exec("CREATE TABLE IF NOT EXISTS legal_acceptances (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NULL,
            restaurante_id INT NULL,
            email_lookup CHAR(64) NULL,
            contexto VARCHAR(40) NOT NULL,
            terms_version VARCHAR(40) NOT NULL,
            terms_hash CHAR(64) NOT NULL,
            privacy_version VARCHAR(40) NOT NULL,
            privacy_hash CHAR(64) NOT NULL,
            cookie_version VARCHAR(40) NULL,
            cookie_hash CHAR(64) NULL,
            ip_hash CHAR(64) NOT NULL,
            user_agent_hash CHAR(64) NOT NULL,
            accepted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_legal_user (usuario_id, accepted_at),
            INDEX idx_legal_restaurant (restaurante_id, accepted_at),
            INDEX idx_legal_email (email_lookup),
            INDEX idx_legal_versions (terms_version, privacy_version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS data_subject_requests (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id CHAR(36) NOT NULL UNIQUE,
            request_type VARCHAR(30) NOT NULL,
            nome TEXT NOT NULL,
            email TEXT NOT NULL,
            email_lookup CHAR(64) NOT NULL,
            restaurante TEXT NULL,
            descricao TEXT NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'PENDENTE_VERIFICACAO',
            ip_hash CHAR(64) NOT NULL,
            user_agent_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_dsr_email (email_lookup),
            INDEX idx_dsr_status (status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $columns = [];
        $columnStmt = $db->query('SHOW COLUMNS FROM data_subject_requests');
        foreach ($columnStmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $columns[(string)$column['Field']] = true;
        }

        $requiredColumns = [
            'admin_notes' => 'TEXT NULL AFTER status',
            'handled_by' => 'INT NULL AFTER admin_notes',
            'internal_notified_at' => 'DATETIME NULL AFTER handled_by',
            'receipt_sent_at' => 'DATETIME NULL AFTER internal_notified_at',
            'resolved_at' => 'DATETIME NULL AFTER receipt_sent_at',
        ];
        foreach ($requiredColumns as $column => $definition) {
            if (!isset($columns[$column])) {
                $db->exec('ALTER TABLE data_subject_requests ADD COLUMN `' . $column . '` ' . $definition);
            }
        }

        $done = true;
    }
}

if (!function_exists('legal_record_acceptance')) {
    function legal_record_acceptance(PDO $db, array $data): int
    {
        $config = legal_config();
        $email = personal_data_normalize_email((string)($data['email'] ?? ''));
        $ip = trim((string)($data['ip'] ?? 'unknown'));
        $userAgent = trim((string)($data['user_agent'] ?? 'unknown'));

        $stmt = $db->prepare("INSERT INTO legal_acceptances (
            usuario_id, restaurante_id, email_lookup, contexto,
            terms_version, terms_hash, privacy_version, privacy_hash,
            cookie_version, cookie_hash, ip_hash, user_agent_hash, accepted_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([
            !empty($data['usuario_id']) ? (int)$data['usuario_id'] : null,
            !empty($data['restaurante_id']) ? (int)$data['restaurante_id'] : null,
            $email !== '' ? personal_data_email_lookup($email) : null,
            substr((string)($data['contexto'] ?? 'cadastro'), 0, 40),
            $config['terms_version'],
            legal_document_hash('terms'),
            $config['privacy_version'],
            legal_document_hash('privacy'),
            $config['cookie_version'],
            legal_document_hash('cookies'),
            legal_request_hash($ip),
            legal_request_hash($userAgent),
        ]);

        return (int)$db->lastInsertId();
    }
}

if (!function_exists('legal_uuid_v4')) {
    function legal_uuid_v4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}

if (!function_exists('legal_create_data_request')) {
    function legal_create_data_request(PDO $db, array $data): string
    {
        $allowed = ['ACESSO', 'CORRECAO', 'ELIMINACAO', 'LIMITACAO', 'OPOSICAO', 'PORTABILIDADE'];
        $type = strtoupper(trim((string)($data['request_type'] ?? '')));
        if (!in_array($type, $allowed, true)) {
            throw new InvalidArgumentException('Tipo de pedido invalido.');
        }

        $email = personal_data_normalize_email((string)($data['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Email invalido.');
        }

        $publicId = legal_uuid_v4();
        $stmt = $db->prepare("INSERT INTO data_subject_requests (
            public_id, request_type, nome, email, email_lookup, restaurante,
            descricao, status, ip_hash, user_agent_hash, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'PENDENTE_VERIFICACAO', ?, ?, NOW(), NOW())");
        $stmt->execute([
            $publicId,
            $type,
            personal_data_encrypt(trim((string)($data['nome'] ?? ''))),
            personal_data_encrypt($email),
            personal_data_email_lookup($email),
            personal_data_encrypt(trim((string)($data['restaurante'] ?? ''))),
            personal_data_encrypt(trim((string)($data['descricao'] ?? ''))),
            legal_request_hash(trim((string)($data['ip'] ?? 'unknown'))),
            legal_request_hash(trim((string)($data['user_agent'] ?? 'unknown'))),
        ]);

        return $publicId;
    }
}
