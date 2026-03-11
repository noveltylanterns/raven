# Raven CLI completion helpers for bash/zsh shells.
# Source this file from ~/.bashrc or ~/.zshrc, for example:
#   source /path/to/private/bin/rvn.sh

_rvn_complete()
{
  local current prev
  COMPREPLY=()
  current="${COMP_WORDS[COMP_CWORD]}"
  prev="${COMP_WORDS[COMP_CWORD-1]}"

  local commands="category channel group tag redirect config theme ext system update"

  if [[ ${COMP_CWORD} -eq 1 ]]; then
    COMPREPLY=( $(compgen -W "${commands}" -- "${current}") )
    return 0
  fi

  case "${COMP_WORDS[1]}" in
    category|channel|group|tag|redirect)
      COMPREPLY=( $(compgen -W "list show create update delete --help --interactive --verbose --verbose-errors --json" -- "${current}") )
      ;;
    config)
      COMPREPLY=( $(compgen -W "list get set sync-defaults --key --value --type --prefix --help --interactive --verbose --verbose-errors --json" -- "${current}") )
      ;;
    theme)
      COMPREPLY=( $(compgen -W "list enable create delete --slug --name --parent --clone --set-default --help --interactive --verbose --verbose-errors --json" -- "${current}") )
      ;;
    ext)
      COMPREPLY=( $(compgen -W "list enable disable create import delete --slug --archive --type --name --version --description --author --homepage --author-url --help --interactive --verbose --verbose-errors --json" -- "${current}") )
      ;;
    system)
      COMPREPLY=( $(compgen -W "info version env extensions --help --json" -- "${current}") )
      ;;
    update)
      COMPREPLY=( $(compgen -W "check run rollback --source --branch --rollback-ref --yes --clean --help --interactive --verbose --verbose-errors --json" -- "${current}") )
      ;;
    *)
      COMPREPLY=( $(compgen -W "${commands}" -- "${current}") )
      ;;
  esac

  return 0
}

complete -F _rvn_complete rvn
complete -F _rvn_complete rvn-cat
complete -F _rvn_complete rvn-chan
complete -F _rvn_complete rvn-group
complete -F _rvn_complete rvn-tag
complete -F _rvn_complete rvn-redir
complete -F _rvn_complete rvn-conf
complete -F _rvn_complete rvn-theme
complete -F _rvn_complete rvn-ext
complete -F _rvn_complete rvn-sys
complete -F _rvn_complete rvn-update
