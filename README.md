# SAF-T Portugal para [DOLIBARR ERP & CRM](https://www.dolibarr.org)

## Recursos

O objetivo deste modulo Saf-T é para consultar ficheiros saft (xml) e ter a possibilidade de importar a(s) fatura(s) para o dolibarr.

<!--
![Screenshot saft](img/screenshot_saft.png?raw=true "Saft"){imgmd}
-->


## Tradução

No momento o modulo está na lingua portuguesa e no futuro a edição para outras linguas será possível em `langs`.

<!--
This module contains also a sample configuration for Transifex, under the hidden directory [.tx](.tx), so it is possible to manage translation using this service.

For more information, see the [translator's documentation](https://wiki.dolibarr.org/index.php/Translator_documentation).

There is a [Transifex project](https://transifex.com/projects/p/dolibarr-module-template) for this module.
-->


## Instalação

Pre-requisitos: Voce precisa ter o sistema Dolibarr ERP & CRM software instalado. Você pode fazer o donwload aqui [Dolistore.org](https://www.dolibarr.org).


### A partir do arquivo ZIP and interface GUI

Nome `module_saft-2.0.zip` (e.g., Quando o download é feito a partir do marketplace como [Dolistore](https://www.dolistore.com)), clique no menu `Home> Setup> Modules> Deploy external module` e faça upload do arquivo zip.

<!--

Note: If this screen tells you that there is no "custom" directory, check that your setup is correct:

- In your Dolibarr installation directory, edit the `htdocs/conf/conf.php` file and check that following lines are not commented:

    ```php
    //$dolibarr_main_url_root_alt ...
    //$dolibarr_main_document_root_alt ...
    ```

- Uncomment them if necessary (delete the leading `//`) and assign the proper value according to your Dolibarr installation

    For example :

    - UNIX:
        ```php
        $dolibarr_main_url_root_alt = '/custom';
        $dolibarr_main_document_root_alt = '/var/www/Dolibarr/htdocs/custom';
        ```

    - Windows:
        ```php
        $dolibarr_main_url_root_alt = '/custom';
        $dolibarr_main_document_root_alt = 'C:/My Web Sites/Dolibarr/htdocs/custom';
        ```
-->

<!--

### From a GIT repository

Clone the repository in `$dolibarr_main_document_root_alt/saft`

```shell
cd ....../custom
git clone git@github.com:gitlogin/saft.git saft
```

-->

### Passos finais

Use seu navegador:

  - Faça login no Dolibarr como super-administrator
  - Vá em "Setup"> "Modules"
  - Você estará apto a procurar o modulo e ativa-lo



## Licenses

### Main code

GPLv3 or (at your option) any later version. See file COPYING for more information.

### Documentation

All texts and readme's are licensed under [GFDL](https://www.gnu.org/licenses/fdl-1.3.en.html).
