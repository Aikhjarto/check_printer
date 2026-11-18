# bash completion for check_printer.php                      -*- shell-script -*-

_check_printer_php()
{
    local cur=${COMP_WORDS[COMP_CWORD]} prev=${COMP_WORDS[COMP_CWORD-1]}
    local i word args=()
    COMPREPLY=()

    case $prev in
        -t | -r)
            return
            ;;
    esac

    # the positional arguments before the current word, without the options
    for ((i = 1; i < COMP_CWORD; i++)); do
        word=${COMP_WORDS[i]}
        case $word in
            -t | -r) ((i++)) ;;
            -*) [ ${#args[@]} -gt 0 ] && args+=("$word") ;;
            *) args+=("$word") ;;
        esac
    done

    case ${#args[@]} in
        0)
            if [[ $cur == -* ]]; then
                mapfile -t COMPREPLY < <(compgen -W '-t -r -V -h' -- "$cur")
            else
                mapfile -t COMPREPLY < <(compgen -A hostname -- "$cur")
            fi
            ;;
        2)
            mapfile -t COMPREPLY < <(compgen -W '1 2c' -- "$cur")
            ;;
        3)
            mapfile -t COMPREPLY < <(compgen -W 'list toner toner_kyocera_monochrome
                toner_ricoh_monochrome toner_ricoh_color paper parts hardware alerts
                counter stats_counter accounting' -- "$cur")
            ;;
        4)
            case ${args[3]} in
                toner | ink | toner/ink)
                    mapfile -t COMPREPLY < <(compgen -W 'all' -- "$cur")
                    ;;
            esac
            ;;
    esac
}
complete -F _check_printer_php check_printer.php
