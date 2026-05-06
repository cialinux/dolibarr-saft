# SAF-T Portugal – Dolibarr Module & API Platform

## O que é o SAF-T (PT)

O **SAF-T PT (Standard Audit File for Tax Purposes – Portuguese Version)** é um ficheiro normalizado em formato **XML** que contém um conjunto estruturado de informações fiscais e contabilísticas de uma empresa.

Este ficheiro é utilizado para comunicar informação relevante à **Autoridade Tributária e Aduaneira (AT)** e inclui dados como:

- Faturas
- Notas de crédito
- Notas de débito
- Recibos
- Documentos de transporte

O SAF-T é obrigatório para empresas que utilizam software de faturação certificado em Portugal.

📚 Fonte oficial:
https://info.portaldasfinancas.gov.pt

---

# Módulo SAF-T Portugal para Dolibarr

O módulo **SAF-T Portugal para Dolibarr** permite validar e importar automaticamente faturas a partir de ficheiros SAF-T (PT), garantindo integridade dos dados e facilitando processos de migração, auditoria ou integração entre sistemas.

Este módulo foi desenvolvido para **empresas, contabilistas e integradores** que necessitam importar documentos fiscais de forma segura e controlada para dentro do ERP **Dolibarr**.

O **SAF-T Portugal** atualmente é um third-party e está focado em consultas e importação do xml de faturas que são gerados a partir de outro aplicativos. O SAF-T Portugal, atualmente, não realiza emissão de faturas.

📚 Fonte oficial:
https://faturas.faturaweb.com/

---

## Licenciamento e arquitetura

- O código deste módulo Dolibarr é distribuído sob **GPL v3+** (ver ficheiro `COPYING`), alinhado com os requisitos do ecossistema Dolibarr.
- A lógica de negócio SAF-T (validação, parsing, deduplicação e análise) é executada no serviço **SAF-T Validator API**.
- O módulo atua como camada de integração UI/ERP para consumir essa API.
- A utilização de todos os recursos da plataforma depende de **licenciamento comercial pago da API**.

Em resumo: o módulo é open source (GPL), mas os recursos completos do serviço exigem subscrição/licença ativa da API.

---

## Funcionalidades do módulo

### Validação de ficheiros SAF-T

O módulo inclui um sistema completo de validação que permite analisar o ficheiro antes da importação.

Funções disponíveis:

- Validação da estrutura XML
- Leitura completa do ficheiro SAF-T
- Identificação do número total de documentos
- Visualização das faturas antes da importação
- Verificação de integridade das informações fiscais

Isto permite identificar problemas antes de importar dados para o ERP.

---

### Importação de faturas

O módulo permite importar faturas diretamente a partir do ficheiro SAF-T para o Dolibarr.

A importação pode ser feita:

- Individualmente
- Em massa (batch import)

Durante o processo o sistema executa múltiplos controlos automáticos.

---

### Controlo de duplicação

Para garantir integridade da base de dados, o módulo inclui verificação automática de duplicados.

O sistema:

- Identifica faturas já existentes no ERP
- Bloqueia a importação de documentos duplicados
- Detecta duplicações dentro do próprio ficheiro SAF-T

Além disso, apresenta contadores detalhados:

- Total de faturas no ficheiro
- Número de faturas duplicadas no ERP
- Número de faturas duplicadas no XML
- Número de faturas prontas para importação

---

### Registo automático de clientes

Durante a importação o sistema verifica se o cliente já existe no ERP.

Caso não exista, o cliente é criado automaticamente com base nos dados presentes no SAF-T.

Informações importadas:

- Nome do cliente
- NIF
- Morada completa
- País
- Código postal
- Cidade

Isto permite realizar **migrações completas de sistemas sem necessidade de cadastro manual**.

---

### Registo completo de informação fiscal

Cada fatura importada mantém informações relevantes para auditoria e rastreabilidade.

O sistema guarda automaticamente nas notas do documento:

- Origem da importação (SAF-T)
- Data da importação
- Número oficial da fatura
- Hash do documento
- Controlo de hash
- SourceID
- Data original de geração do documento
- Código da taxa de exceção
- Motivo da taxa de exceção

Isto garante rastreabilidade completa do documento fiscal.

---

## Benefícios do módulo

- Importação rápida de grandes volumes de documentos
- Migração simplificada entre sistemas de faturação
- Controlo automático de duplicações
- Criação automática de clientes
- Preservação da integridade fiscal dos documentos
- Integração nativa com Dolibarr

---

## Casos de uso

Este módulo é ideal para:

- Empresas que estão a migrar de outro ERP
- Contabilistas que recebem ficheiros SAF-T de clientes
- Integradores Dolibarr
- Auditorias fiscais
- Consolidação de sistemas de faturação
