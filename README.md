# Precificação de Serviços com Regras Parametrizáveis

Solução Full Stack para o teste técnico de **Desenvolvedor Full Stack — EMTEC / Prefeitura de Juiz de Fora**.

O sistema calcula o valor final de serviços a partir de um **valor base** ajustado por **regras cadastradas no banco de dados** (condições + ação), e não por condições escritas no código. Um usuário autorizado cria, altera, ativa e desativa regras pela própria aplicação, e a mudança vale imediatamente para os próximos cálculos, sem recompilar nem alterar código.

---

## Sumário

1. [Como executar](#1-como-executar)
2. [Acesso e primeiros passos](#2-acesso-e-primeiros-passos)
3. [Estrutura do projeto](#3-estrutura-do-projeto)
4. [Arquitetura](#4-arquitetura)
5. [Modelo de dados](#5-modelo-de-dados)
6. [Estratégia de parametrização das regras](#6-estratégia-de-parametrização-das-regras)
7. [Mecanismo de cálculo](#7-mecanismo-de-cálculo)
8. [Interpretações do enunciado](#8-interpretações-do-enunciado)
9. [Prioridade e conflitos entre regras](#9-prioridade-e-conflitos-entre-regras)
10. [API REST](#10-api-rest)
11. [Decisões técnicas](#11-decisões-técnicas)
12. [Segurança, manutenção e desempenho](#12-segurança-manutenção-e-desempenho)
13. [Evolução da solução](#13-evolução-da-solução)
14. [Testes](#14-testes)
15. [Solução de problemas](#15-solução-de-problemas)

---

## 1. Como executar

### Pré-requisitos

- Docker Engine 24+ com o plugin **Docker Compose v2** (`docker compose`)
- Portas livres no host: **8080** (aplicação) e **3307** (MySQL, opcional)

### Passo a passo (Linux / VM Ubuntu 24.04)

```bash
# 1. Entrar na pasta do projeto
cd projeto

# 2. (Opcional) Definir senhas próprias; sem este passo valem as senhas padrão do compose
cp .env.example .env

# 3. Construir e subir os 3 containers
docker compose up -d --build

# 4. Acompanhar até o banco ficar "healthy" (cerca de 30 segundos na primeira vez)
docker compose ps
```

Acesse: **http://localhost:8080**

### Comandos úteis

| Comando | Para quê |
|---|---|
| `docker compose ps` | Situação dos containers |
| `docker compose logs -f backend` | Logs do PHP/Apache (erros detalhados ficam aqui) |
| `docker compose up -d --build` | Aplicar alterações no código do backend |
| `docker compose down` | Parar (os dados continuam salvos) |
| `docker compose down -v` | Parar e **apagar o banco** (schema e seed rodam de novo na próxima subida) |
| `docker compose exec backend php tests/MotorCalculoTest.php` | Rodar os testes automatizados do motor |

### Execução sem acesso à internet

A construção precisa baixar as imagens `mysql:8.4`, `php:8.3-apache` e `nginx:1.27-alpine`. O **frontend não depende de nenhuma CDN** e funciona offline. Se a máquina de destino não tiver internet, leve as imagens prontas:

```bash
# Na máquina COM internet (depois de um "docker compose up -d --build" bem-sucedido)
docker save -o imagens.tar mysql:8.4 nginx:1.27-alpine projeto-backend

# Na máquina SEM internet
docker load -i imagens.tar
docker compose up -d        # sem --build: usa a imagem do backend já carregada
```

> O nome da imagem do backend segue o padrão `<pasta>-backend`. Confira com `docker images`.

---

## 2. Acesso e primeiros passos

| Perfil | E-mail | Senha | Permissões |
|---|---|---|---|
| **Administrador** | `admin@precificacao.local` | `admin123` | Tudo: cadastros, regras, cálculos |
| **Operador** | `operador@precificacao.local` | `operador123` | Consulta e executa cálculos |

### Roteiro de verificação rápida

| Ação | Resultado esperado |
|---|---|
| Calcular **Consultoria · Estratégico · Local · 60** | Unitário **R$ 209,00** (R001 e R003) · total R$ 12.540,00 |
| *Regras* → R001 → Editar → Valor **7** → Salvar; calcular de novo | **R$ 204,60** |
| *Regras* → R001 → Histórico | "Desconto de 5% → Desconto de 7%, por Administrador" |
| *Regras* → R003 → Desativar; calcular de novo | **R$ 186,00** |
| Calcular **Desenvolvimento · Privado · Nacional · 5** | 300 × 1,25 = 375 → +15% (R002) = **R$ 431,25** |
| Entrar como Operador | Calcula normalmente, sem botões de alteração |

### Acesso ao banco (opcional)

Cliente SQL (DBeaver, Workbench): host `localhost`, porta **3307**, banco `precificacao`, usuário `app`, senha do `.env` (ou `app_troque_em_producao`). No DBeaver, defina `allowPublicKeyRetrieval=true` nas propriedades do driver.

---

## 3. Estrutura do projeto

```
projeto/
├── docker-compose.yml          # orquestra os 3 containers
├── Dockerfile                  # imagem do backend (PHP 8.3 + Apache)
├── .env.example                # modelo de variáveis de ambiente
├── README.md
│
├── database/
│   ├── 01_schema.sql           # tabelas, índices e restrições
│   └── 02_seed.sql             # dados do enunciado + regras + usuários
│
├── backend/
│   ├── public/index.php        # ponto de entrada único (front controller)
│   ├── config/
│   │   ├── config.php          # configurações via variáveis de ambiente
│   │   └── routes.php          # mapa de todas as rotas e permissões
│   ├── src/
│   │   ├── autoload.php        # carregamento automático de classes (PSR-4)
│   │   ├── Core/               # infraestrutura: Router, Request, Response, Auth, Database, Validador
│   │   ├── Engine/             # ★ motor de cálculo (independente do banco)
│   │   ├── Repositories/       # todo o SQL da aplicação
│   │   └── Controllers/        # recebem a requisição, validam e respondem
│   └── tests/
│       └── MotorCalculoTest.php
│
└── frontend/
    ├── nginx.conf              # serve as telas e repassa /api ao backend
    ├── index.html              # login
    ├── app.html                # sistema (abas Cálculo, Regras, Cadastros, Histórico)
    ├── css/style.css
    └── js/                     # api.js, app.js, calculo.js, regras.js, cadastros.js
```

---

## 4. Arquitetura

```
 Navegador ──► http://localhost:8080
                    │
          ┌─────────▼──────────┐
          │ frontend (Nginx)   │  /       → HTML, CSS e JS
          │                    │  /api/*  → proxy para o backend
          └─────────┬──────────┘
                    │ rede interna do Docker (sem porta exposta)
          ┌─────────▼──────────┐
          │ backend (PHP 8.3)  │  index.php → Router → Controller
          │                    │                 │         │
          │                    │          Repository   Engine (motor)
          └─────────┬──────────┘                 │
                    │ PDO (prepared statements)  │
          ┌─────────▼──────────┐                 │
          │ db (MySQL 8.4)     │ ◄───────────────┘
          └────────────────────┘
```

### Camadas do backend

| Camada | Responsabilidade | Não faz |
|---|---|---|
| **Router** (`Core/Router.php`) | Liga método + URL ao controller e **verifica a permissão** da rota | Regra de negócio |
| **Controllers** | Validam a entrada e orquestram repositório + motor | SQL, cálculo |
| **Repositories** | Único lugar com SQL | Validação, cálculo |
| **Engine** | Calcula o preço e monta a memória | Acessar banco ou HTTP |

O **motor de cálculo é uma função pura**: recebe todos os dados prontos (serviço, região, regras…) e devolve o resultado. Ele não conhece o banco, e por isso é testado isoladamente.

---

## 5. Modelo de dados

```
usuarios ─┬──────────────< regras >──────────< regra_condicoes
          │                  │
          │                  └──────────────< regras_historico   (auditoria)
          │
          └──< calculos >── servicos / categorias_cliente / regioes

faixas_utilizacao   (consultada pelo motor para identificar a faixa da quantidade)
```

| Tabela | Conteúdo |
|---|---|
| `servicos` | Serviço e **valor base** unitário |
| `categorias_cliente` | Público, Privado, Estratégico… |
| `regioes` | Região e **fator de preço** (1,00 = sem alteração) |
| `faixas_utilizacao` | Intervalos de quantidade (`quantidade_final` NULL = "ou superior") |
| `regras` | O **"APLICAR"**: tipo de ação, forma, valor, prioridade, interromper, ativa, vigência |
| `regra_condicoes` | O **"QUANDO"**: N condições por regra (`campo`, `operador`, `valor`, `valor_final`) |
| `regras_historico` | Auditoria: criação, alteração, ativação e desativação, com o antes e o depois em JSON |
| `calculos` | Histórico de cálculos com a **memória completa em JSON** |
| `usuarios` | Login e perfil (ADMIN / OPERADOR), senha com hash bcrypt |

### Decisões do modelo

- **Regra e condições em tabelas separadas (1:N).** Uma regra pode ter quantas condições precisar. Um novo critério não exige colunas novas.
- **`campo` e `operador` em VARCHAR, e não ENUM.** A lista de valores válidos fica em uma única classe PHP. Adicionar um operador não exige migração no banco.
- **Dinheiro em `DECIMAL`**, nunca em ponto flutuante.
- **Exclusão lógica** (`ativo`/`ativa`): nada é apagado. O histórico de cálculos continua íntegro.
- **Memória de cálculo como "fotografia" (JSON).** Se preços ou regras mudarem, um cálculo antigo continua mostrando exatamente o que foi aplicado na época.
- **Índice `(ativa, prioridade)`**, usado pela consulta do motor.
- **CHECK constraints** (valores ≥ 0, faixa final ≥ inicial, vigência válida): segunda camada de validação além da aplicação.

---

## 6. Estratégia de parametrização das regras

Toda regra tem três partes, todas cadastradas pela tela:

| Parte | Conteúdo | Exemplo (Regra 1 do enunciado) |
|---|---|---|
| **Quando** (condições) | Lista de `campo + operador + valor`, combinadas com **E** | `Categoria = Estratégico` **e** `Quantidade > 50` |
| **Aplicar** (ação) | Desconto ou acréscimo, percentual ou valor fixo | Desconto de 5% |
| **Controle** | Prioridade, interromper, ativa, vigência (datas) | Prioridade 10 |

Como a Regra 1 fica gravada:

```
regras:           R001 | prioridade 10 | DESCONTO | PERCENTUAL | 5.0000 | ativa
regra_condicoes:  CATEGORIA  | IGUAL | 3        (3 = id de "Estratégico")
                  QUANTIDADE | MAIOR | 50
```

O código conhece apenas **os campos e os operadores possíveis**. As combinações entre eles são dados.

| Campos | Operadores |
|---|---|
| `SERVICO`, `CATEGORIA`, `REGIAO`, `FAIXA` (valor = id do cadastro) · `QUANTIDADE` (número) | `=` `≠` `>` `≥` `<` `≤` `entre` (inclusivo) `em` (lista) |

Os operadores de comparação numérica (`>`, `entre`…) não são aceitos para campos de cadastro. Para "OU", cadastram-se duas regras.

### Regras carregadas no seed

| Código | Prioridade | Quando | Aplicar | Origem |
|---|---|---|---|---|
| R001 | 10 | Categoria = Estratégico **e** Quantidade > 50 | −5% | Regra 1 do enunciado |
| R002 | 20 | Região = Nacional **e** Serviço = Desenvolvimento | +15% | Regra 2 do enunciado |
| R003 | 30 | Quantidade entre 51 e 100 | +10% | Regra 3 do enunciado |
| R004 | 40 | Faixa = 11 a 50 | +5% | Tabela de faixas |
| R005 | 50 | Faixa = 101 ou superior | +20% | Tabela de faixas |

Prioridades de 10 em 10 permitem inserir regras entre as existentes sem renumerar.

---

## 7. Mecanismo de cálculo

Classe `backend/src/Engine/MotorCalculo.php`:

```
1. Valor base do serviço
2. × fator da região
3. Identifica a faixa de utilização da quantidade
4. Busca as regras ATIVAS, em ordem de prioridade (desempate pelo id)
5. Para cada regra:
     fora da vigência?      → registra e segue
     alguma condição falha? → registra "não atendida" com o motivo e segue
     todas atendidas       → aplica a ação sobre o SUBTOTAL e registra
                              se "interromper" → para a avaliação
6. Valor unitário final
7. Total = unitário × quantidade
8. Grava o cálculo e a memória no histórico
```

### Memória de cálculo (exemplo do enunciado)

```
Valor base: R$ 200,00

Regra R001 — Desconto Estratégico acima de 50:
  Categoria = Estratégico
  Quantidade > 50
  Desconto de 5% (− R$ 10,00)
Subtotal: R$ 190,00

Regra R003 — Acréscimo quantidade entre 51 e 100:
  Quantidade entre 51 e 100
  Acréscimo de 10% (+ R$ 19,00)
Subtotal: R$ 209,00

Valor unitário final: R$ 209,00
Quantidade: 60
Valor total: R$ 12.540,00
```

Além do texto, a resposta traz a versão estruturada exibida na tela: valor inicial, **todas as regras avaliadas** (inclusive as não aplicadas, com o motivo), resultado de cada condição, valores antes/ajuste/depois, total de descontos, total de acréscimos e valor final. Isso cobre todos os itens da seção 7 do enunciado.

### Situações de uma regra na memória

| Situação | Significado |
|---|---|
| `APLICADA` | Todas as condições atendidas; ação aplicada |
| `NAO_ATENDIDA` | Ao menos uma condição falhou (o motivo é exibido) |
| `FORA_DE_VIGENCIA` | Data do cálculo fora do período da regra |
| `INVALIDA` | Condição corrompida no banco: a regra é ignorada sem derrubar o cálculo |

### Precisão monetária

Todas as contas usam **bcmath** (números decimais exatos, sem erro de ponto flutuante), com **arredondamento meio-para-cima em 2 casas a cada passo**. Assim, a soma dos passos exibidos na memória sempre fecha com o valor final. Um desconto nunca deixa o preço negativo.

---

## 8. Interpretações do enunciado

Pontos ambíguos do enunciado e a decisão adotada, validada contra o exemplo da seção 7 do PDF:

| Ponto | Decisão | Justificativa |
|---|---|---|
| Base dos percentuais | **Cumulativos** sobre o subtotal | O exemplo dá 200 × 0,95 × 1,10 = **209,00**. Sobre a base daria 210,00 |
| Valor calculado | **Unitário**; total = unitário × quantidade | R$ 209 com quantidade > 50 só faz sentido como valor unitário |
| Fator de região | Aplicado **automaticamente** sobre o valor base | É um atributo da região, não uma regra condicional |
| Faixas × Regra 3 | A faixa é **cadastro usado como condição**; o acréscimo entra só por regra | A faixa 51–100 (+10%) e a Regra 3 (+10%) são o mesmo ajuste. Aplicar os dois cobraria 10% duas vezes (**229,90** em vez de 209,00). Por isso a faixa 51–100 não tem regra própria, e o percentual da faixa é informativo |
| "Usuário autorizado" | Perfis **ADMIN** (altera) e **OPERADOR** (consulta e calcula) | Separação mínima entre quem configura e quem usa |

---

## 9. Prioridade e conflitos entre regras

- **Ordem:** menor prioridade executa primeiro. Em empate, vale o menor id, o que garante resultado **sempre determinístico**.
- **Quando a ordem importa:** com percentuais cumulativos ela não altera o resultado (0,95 × 1,10 = 1,10 × 0,95). Com **valor fixo**, altera. Exemplo, com desconto de R$ 20 e acréscimo de 10% sobre R$ 200:
  - desconto antes: (200 − 20) × 1,10 = **R$ 198,00**
  - desconto depois: 200 × 1,10 − 20 = **R$ 200,00**
- **Regras exclusivas (`interromper`):** se uma regra com essa opção for aplicada, as regras seguintes **não são avaliadas**. Resolve conflitos do tipo "contrato público tem 12% de desconto e nenhum outro ajuste": Consultoria · Público · 60 un. resulta em **R$ 176,00** (sem interromper, a R003 também entraria e daria R$ 193,60).
- **Vigência:** regras temporárias (promoções) com data de início e fim; fora do período, não participam.

---

## 10. API REST

Todas as respostas seguem o mesmo formato:

```json
{ "sucesso": true,  "dados": { ... } }
{ "sucesso": false, "erro": { "mensagem": "...", "detalhes": { "campo": "motivo" } } }
```

| Método | Rota | Acesso | Descrição |
|---|---|---|---|
| GET | `/api/status` | público | Saúde da API e do banco |
| POST | `/api/auth/login` | público | Login (`email`, `senha`) |
| POST | `/api/auth/logout` | logado | Encerrar sessão |
| GET | `/api/auth/me` | logado | Usuário da sessão |
| GET | `/api/{recurso}` | logado | Listar (`?ativos=1` para só ativos) |
| GET | `/api/{recurso}/{id}` | logado | Consultar |
| POST | `/api/{recurso}` | **admin** | Cadastrar |
| PUT | `/api/{recurso}/{id}` | **admin** | Alterar |
| PATCH | `/api/{recurso}/{id}/status` | **admin** | Ativar/desativar (`{"ativo": true}`) |
| GET | `/api/regras/metadados` | logado | Campos, operadores e opções para montar o editor |
| GET | `/api/regras` | logado | Listar (`?ativa=1\|0`) |
| GET | `/api/regras/{id}` | logado | Consultar com condições |
| GET | `/api/regras/{id}/historico` | logado | Auditoria de alterações |
| POST | `/api/regras` | **admin** | Cadastrar regra e condições |
| PUT | `/api/regras/{id}` | **admin** | Alterar regra e condições |
| PATCH | `/api/regras/{id}/status` | **admin** | Ativar/desativar (`{"ativa": false}`) |
| POST | `/api/calculos` | logado | Executar cálculo (`servico_id`, `categoria_id`, `regiao_id`, `quantidade`) |
| GET | `/api/calculos` | logado | Histórico resumido (`?limite=50`) |
| GET | `/api/calculos/{id}` | logado | Cálculo com memória completa |

`{recurso}` = `servicos`, `categorias`, `regioes` ou `faixas`.

Códigos HTTP usados: 200, 201, 400 (requisição malformada), 401 (sem login), 403 (sem permissão), 404, 405, 409 (duplicidade/conflito), 422 (validação, com erros por campo) e 500.

---

## 11. Decisões técnicas

| Decisão | Justificativa |
|---|---|
| **PHP 8.3 puro, sem framework** | A lógica central (roteamento, validação, motor) fica explícita e explicável. Um framework esconderia isso. Sem dependências externas: nada de Composer para instalar |
| **MySQL 8.4 (LTS)** | Suporte a `JSON` (memória e auditoria), `DECIMAL` exato e CHECK constraints |
| **Nginx na frente + proxy `/api`** | Front e API na **mesma origem**: sem CORS e com o cookie de sessão funcionando naturalmente. O backend não expõe porta |
| **Frontend em HTML/JS/CSS puros, sem CDN** | Funciona **offline** e dispensa etapa de build |
| **Motor como função pura** | Testável sem banco; controller busca os dados, motor só calcula |
| **Operadores em lista fechada (whitelist)** | As regras são dados interpretados, nunca código executado (sem `eval`) |
| **bcmath para dinheiro** | Precisão decimal exata, sem centavos "fantasmas" |
| **Editor de regras dirigido por metadados** | A tela monta campos e operadores a partir de `/api/regras/metadados`. Um operador novo no motor aparece na tela sem alterar o frontend |
| **Raiz pública = `backend/public`** | Apenas o `index.php` é acessível; `config/` e `src/` ficam fora do alcance da web |
| **Controle de acesso declarado na rota** | Cada rota diz se é pública, logada ou admin. Nenhum controller precisa lembrar de checar permissão |
| **Validação que acumula erros (422)** | O usuário vê todos os problemas de uma vez, cada um no seu campo |
| **Transações** | Regra, condições e auditoria são gravadas juntas, ou nada é gravado |

---

## 12. Segurança, manutenção e desempenho

### Segurança

- **SQL injection:** PDO com *prepared statements* reais (`EMULATE_PREPARES = false`); nomes de tabelas e colunas vêm de constantes, nunca do usuário; filtro de colunas graváveis.
- **Injeção de código:** operadores e campos em lista fechada; sem `eval`.
- **Autenticação:** senhas com `password_hash` (bcrypt); novo ID de sessão no login (contra *session fixation*); cookie `HttpOnly` + `SameSite=Strict`; mesma mensagem e mesmo tempo de resposta para e-mail inexistente e senha errada (não revela usuários cadastrados).
- **Autorização:** verificada na API (403).
- **Exposição de informações:** `display_errors=Off`, versões do PHP/Apache/Nginx ocultas, erros técnicos apenas no log (`APP_DEBUG=0`).
- **Integridade:** validação na aplicação + CHECK/UNIQUE/FK no banco; faixas de utilização sem sobreposição.

### Manutenção

- Separação clara de camadas; SQL concentrado nos repositórios.
- Os quatro cadastros compartilham a mesma base (`CrudRepository`, `CadastroController` e a configuração em `cadastros.js`).
- Mapa único de rotas (`config/routes.php`).
- Auditoria completa das regras e memória de cálculo preservada.
- **Modo debug** (`APP_DEBUG=1`): a resposta inclui tipo, mensagem, arquivo e linha do erro. Com `0`, só no log.
- Testes automatizados do motor.

### Desempenho

- Regras ativas buscadas em uma consulta que usa o índice `(ativa, prioridade)`.
- Condições de todas as regras carregadas numa **única consulta** (evita o problema N+1).
- Conexão com o banco única por requisição, aberta só quando necessária.
- O motor é linear no número de regras ativas: O(regras × condições).
- Listagens de histórico sem a memória completa (resumo leve); a memória só é carregada ao abrir um cálculo.

---

## 13. Evolução da solução

| Necessidade | O que fazer |
|---|---|
| **Novo operador** (ex.: "não está em") | Uma entrada em `Operadores::LISTA` + o caso no `match` de `avaliar()`. Banco e tela não mudam |
| **Novo campo de condição** (ex.: dia da semana) | Uma entrada em `AvaliadorCondicao::CAMPOS` + o valor no contexto do `MotorCalculo`. A tela o exibe automaticamente |
| **Novo cadastro** | Repositório e controller herdando das bases + uma entrada em `CONFIG_CADASTROS` |
| **Condições com "OU"** | Grupos de condições (tabela `regra_grupos`), mantendo a compatibilidade com as regras atuais |
| **Nova ação** (ex.: preço fixo) | Novo `tipo_valor` no `AplicadorAcao` |

Próximos passos possíveis: simulação de cálculo sem gravar histórico; versionamento de regras com data de efeito; limite de tentativas de login e token CSRF; paginação e filtros no histórico; testes de integração da API.

---

## 14. Testes

### Automatizados (motor de cálculo)

```bash
docker compose exec backend php tests/MotorCalculoTest.php
```

São 25 cenários, sem dependência externa nem banco:

- exemplo do enunciado (R$ 209,00, subtotal R$ 190,00)
- **requisito principal** (5% → 7% = R$ 204,60)
- todas as regras do seed, incluindo o fator de região
- não duplicação do acréscimo da faixa 51–100
- prioridade com valor fixo, interrupção, desempate determinístico
- regra inativa, fora de vigência e com condição inválida
- bordas dos operadores (`entre` inclusivo, `>` estrito), identificação de faixas, validação de condições
- arredondamento e precisão (0,1 + 0,2 = 0,30), formato da memória

Resultado esperado: `✅ 25 testes, todos passaram.`

### Manuais

A API e as telas foram verificadas de ponta a ponta: login, cálculos, criação/edição/desativação de regras, auditoria, validações (422), duplicidade (409), permissões (403), sessão (401). O roteiro da [seção 2](#roteiro-de-verificação-rápida) reproduz os principais casos.

---

## 15. Solução de problemas

| Sintoma | Causa provável | Solução |
|---|---|---|
| `Access denied for user 'app'` | O banco foi criado com outra senha | `docker compose down -v` e subir de novo |
| Alteração no PHP não aparece | O código do backend é copiado para a imagem | `docker compose up -d --build` |
| `Class "App\..." not found` | Nome de pasta ou arquivo diferente da classe (o Linux diferencia maiúsculas) | Conferir com `docker compose exec backend find /var/www/html/src -type f` |
| 404 em `/js/...` ou `/css/...` | Arquivo fora de `frontend/js` ou `frontend/css` | Conferir com `docker compose exec frontend ls -R /usr/share/nginx/html` |
| Erro 500 sem detalhes | `APP_DEBUG=0` (padrão de entrega) | `docker compose logs backend` ou `APP_DEBUG=1` no `.env` |
| `invalid interpolation format` | Faltou o hífen em `${VAR:-padrão}` no compose | Corrigir a sintaxe |
| Porta 8080 ou 3307 ocupada | Outro serviço no host | Alterar o lado esquerdo do mapeamento em `ports:` |
| Mudança no seed não aparece | Scripts do banco só rodam com o volume vazio | `docker compose down -v` |