#!/usr/bin/env bash
#
# setup_blueprint.sh - Montgomery Community College blueprint generator.
#
# Generates, relative to this script (the Varbase project root):
#   recipes/mc_college_structure/       Content model recipe (config + actions)
#   (web/modules/custom/mc_college_core and scripts/*.php are maintained as source files)
#   web/themes/custom/mc_college/       Theme generated from the Educare starterkit + brand CSS
#
# Usage:  bash setup_blueprint.sh
#
# Re-running regenerates everything except the generated theme (which is only
# created once; its brand CSS is refreshed on every run). Edit the definitions
# in this script, not the generated files.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RECIPE="$ROOT/recipes/mc_college_structure"
CFG="$RECIPE/config"
MODULE="$ROOT/web/modules/custom/mc_college_core"
THEME="$ROOT/web/themes/custom/mc_college"
SCRIPTS="$ROOT/scripts"

echo "→ Generating blueprint under $ROOT"
mkdir -p "$CFG"
rm -f "$CFG"/*.yml

# ═══════════════════════════════════════════════════════════════════
# YAML helpers
# ═══════════════════════════════════════════════════════════════════

# Field kinds used below:
#   string string_long integer decimal boolean date daterange email link
#   ref_term ref_node ref_user ref_media list registration
field_type_of() {
  case "$1" in
    string) echo string ;;
    string_long) echo string_long ;;
    integer) echo integer ;;
    decimal) echo decimal ;;
    boolean) echo boolean ;;
    date) echo datetime ;;
    daterange) echo daterange ;;
    email) echo email ;;
    link) echo link ;;
    ref_term|ref_node|ref_user|ref_media) echo entity_reference ;;
    list) echo list_string ;;
    registration) echo registration ;;
    *) echo "Unknown field kind: $1" >&2; exit 1 ;;
  esac
}

storage_module_of() {
  case "$1" in
    date) echo datetime ;;
    daterange) echo datetime_range ;;
    link) echo link ;;
    list) echo options ;;
    registration) echo registration ;;
    *) echo core ;;
  esac
}

# "value|Label" lines for list fields.
allowed_values() {
  case "$1" in
    field_student_type)
      printf '%s\n' 'matriculated|Matriculated' 'non_matriculated|Non-Matriculated' ;;
    field_enrollment_status)
      printf '%s\n' 'applicant|Applicant' 'active|Active' 'on_leave|On leave' 'graduated|Graduated' 'withdrawn|Withdrawn from college' ;;
    field_residency)
      printf '%s\n' 'in_district|In-District' 'out_of_district|Out-of-District' 'out_of_state|Out-of-State' ;;
    field_grade)
      printf '%s\n' 'A|A (4.0)' 'B|B (3.0)' 'C|C (2.0)' 'D|D (1.0)' 'F|F (0.0)' 'P|P (Pass, not in GPA)' 'I|I (Incomplete, not in GPA)' ;;
  esac
}

# storage ENTITY FIELD KIND [MAX_LENGTH]
storage() {
  local ent=$1 name=$2 kind=$3 arg=${4:-}
  local ftype module settings deps
  ftype=$(field_type_of "$kind")
  module=$(storage_module_of "$kind")
  deps="    - $ent"
  case "$kind" in
    string)
      settings=$'settings:\n  max_length: '"${arg:-255}"$'\n  case_sensitive: false\n  is_ascii: false' ;;
    integer)
      settings=$'settings:\n  unsigned: false\n  size: normal' ;;
    decimal)
      settings=$'settings:\n  precision: 10\n  scale: 2' ;;
    date)
      settings=$'settings:\n  datetime_type: date'
      deps+=$'\n    - datetime' ;;
    daterange)
      settings=$'settings:\n  datetime_type: datetime'
      deps+=$'\n    - datetime_range' ;;
    ref_term)
      settings=$'settings:\n  target_type: taxonomy_term'
      deps+=$'\n    - taxonomy' ;;
    ref_node)
      settings=$'settings:\n  target_type: node'
      [[ $ent != node ]] && deps+=$'\n    - node' ;;
    ref_user)
      settings=$'settings:\n  target_type: user'
      [[ $ent != user ]] && deps+=$'\n    - user' ;;
    ref_media)
      settings=$'settings:\n  target_type: media'
      deps="    - media"$'\n'"$deps" ;;
    list)
      settings=$'settings:\n  allowed_values:'
      while IFS='|' read -r v l; do
        settings+=$'\n    -\n      value: '"$v"$'\n      label: \''"$l"\'
      done < <(allowed_values "$name")
      settings+=$'\n  allowed_values_function: \'\''
      deps+=$'\n    - options' ;;
    link)
      settings='settings: {  }'
      deps+=$'\n    - link' ;;
    registration)
      settings='settings: {  }'
      deps+=$'\n    - registration' ;;
    *)
      settings='settings: {  }' ;;
  esac
  cat > "$CFG/field.storage.$ent.$name.yml" <<EOF
langcode: en
status: true
dependencies:
  module:
$deps
id: $ent.$name
field_name: $name
entity_type: $ent
type: $ftype
$settings
module: $module
locked: false
cardinality: 1
translatable: true
indexes: {  }
persist_with_no_fields: false
custom_storage: false
EOF
}

declare -A BUNDLE_FIELDS=()   # "entity.bundle" -> "field:kind field:kind ..."

# instance ENTITY BUNDLE FIELD KIND LABEL REQUIRED [TARGET] [DESCRIPTION]
instance() {
  local ent=$1 bundle=$2 name=$3 kind=$4 label=$5 required=$6 target=${7:-} desc=${8:-}
  local ftype settings cdeps mdeps="" default_value='default_value: {  }'
  ftype=$(field_type_of "$kind")
  cdeps="    - field.storage.$ent.$name"
  case "$ent" in
    node) cdeps+=$'\n    - node.type.'"$bundle" ;;
    registration) cdeps+=$'\n    - registration.type.'"$bundle" ;;
  esac
  case "$kind" in
    integer)
      settings=$'settings:\n  min: 0\n  max: null\n  prefix: \'\'\n  suffix: \'\'' ;;
    decimal)
      settings=$'settings:\n  min: 0\n  max: null\n  prefix: $\n  suffix: \'\'' ;;
    boolean)
      settings=$'settings:\n  on_label: \'Yes\'\n  off_label: \'No\'' ;;
    link)
      settings=$'settings:\n  title: 1\n  link_type: 16'
      mdeps="    - link" ;;
    ref_term)
      cdeps+=$'\n    - taxonomy.vocabulary.'"$target"
      settings=$'settings:\n  handler: \'default:taxonomy_term\'\n  handler_settings:\n    target_bundles:\n      '"$target: $target"$'\n    sort:\n      field: name\n      direction: asc\n    auto_create: false\n    auto_create_bundle: \'\'' ;;
    ref_node)
      cdeps+=$'\n    - node.type.'"$target"
      settings=$'settings:\n  handler: \'default:node\'\n  handler_settings:\n    target_bundles:\n      '"$target: $target"$'\n    sort:\n      field: title\n      direction: ASC\n    auto_create: false\n    auto_create_bundle: \'\'' ;;
    ref_user)
      cdeps+=$'\n    - user.role.'"$target"
      settings=$'settings:\n  handler: \'default:user\'\n  handler_settings:\n    target_bundles: null\n    filter:\n      type: role\n      role:\n        '"$target: $target"$'\n    include_anonymous: false\n    sort:\n      field: name\n      direction: ASC\n    auto_create: false' ;;
    ref_media)
      cdeps+=$'\n    - media.type.'"$target"
      settings=$'settings:\n  handler: \'default:media\'\n  handler_settings:\n    target_bundles:\n      '"$target: $target"$'\n    sort:\n      field: _none\n      direction: ASC\n    auto_create: false\n    auto_create_bundle: \'\'' ;;
    registration)
      cdeps+=$'\n    - registration.type.'"$target"
      mdeps="    - registration"
      settings='settings: {  }'
      default_value=$'default_value:\n  -\n    registration_type: '"$target" ;;
    date) mdeps="    - datetime"; settings='settings: {  }' ;;
    daterange) mdeps="    - datetime_range"; settings='settings: {  }' ;;
    list) mdeps="    - options"; settings='settings: {  }' ;;
    *) settings='settings: {  }' ;;
  esac
  {
    echo "langcode: en"
    echo "status: true"
    echo "dependencies:"
    echo "  config:"
    echo "$cdeps"
    if [[ -n $mdeps ]]; then echo "  module:"; echo "$mdeps"; fi
    cat <<EOF
id: $ent.$bundle.$name
field_name: $name
entity_type: $ent
bundle: $bundle
label: '$label'
description: '$desc'
required: $required
translatable: false
$default_value
default_value_callback: ''
$settings
field_type: $ftype
EOF
  } > "$CFG/field.field.$ent.$bundle.$name.yml"
  BUNDLE_FIELDS["$ent.$bundle"]+="$name:$kind "
}

# body BUNDLE LABEL: attach the shared node body field.
body() {
  local bundle=$1 label=$2
  cat > "$CFG/field.field.node.$bundle.body.yml" <<EOF
langcode: en
status: true
dependencies:
  config:
    - field.storage.node.body
    - node.type.$bundle
  module:
    - text
id: node.$bundle.body
field_name: body
entity_type: node
bundle: $bundle
label: '$label'
description: ''
required: false
translatable: true
default_value: {  }
default_value_callback: ''
settings:
  display_summary: true
  required_summary: false
field_type: text_with_summary
EOF
  BUNDLE_FIELDS["node.$bundle"]="body:body ${BUNDLE_FIELDS["node.$bundle"]:-}"
}

node_type() {
  local type=$1 name=$2 desc=$3 help=$4
  cat > "$CFG/node.type.$type.yml" <<EOF
langcode: en
status: true
dependencies:
  module:
    - menu_ui
third_party_settings:
  menu_ui:
    available_menus: {  }
    parent: ''
name: '$name'
type: $type
description: '$desc'
help: '$help'
new_revision: true
preview_mode: 1
display_submitted: false
EOF
}

vocabulary() {
  local vid=$1 name=$2 desc=$3
  cat > "$CFG/taxonomy.vocabulary.$vid.yml" <<EOF
langcode: en
status: true
dependencies: {  }
name: '$name'
vid: $vid
description: '$desc'
weight: 0
new_revision: false
EOF
}

role() {
  local id=$1 label=$2 weight=$3; shift 3
  {
    cat <<EOF
langcode: en
status: true
dependencies: {  }
id: $id
label: '$label'
weight: $weight
is_admin: false
permissions:
EOF
    for p in "$@"; do echo "  - '$p'"; done
  } > "$CFG/user.role.$id.yml"
}

# Widget and formatter YAML, indented by $3 spaces.
emit_component() {
  local type=$1 weight=$2 indent=$3 settings=$4 label=$5 pad
  pad=$(printf '%*s' "$indent" '')
  echo "${pad}type: $type"
  [[ -n $label ]] && echo "${pad}label: $label"
  if [[ -n $settings ]]; then
    echo "${pad}settings:"
    while IFS= read -r line; do echo "${pad}  $line"; done <<< "$settings"
  else
    echo "${pad}settings: {  }"
  fi
  echo "${pad}third_party_settings: {  }"
  echo "${pad}weight: $weight"
  echo "${pad}region: content"
}

widget_yaml() {
  local kind=$1 weight=$2 indent=$3 type settings=''
  case "$kind" in
    body) type=text_textarea_with_summary; settings=$'rows: 9\nsummary_rows: 3\nplaceholder: \'\'\nshow_summary: false' ;;
    string) type=string_textfield; settings=$'size: 60\nplaceholder: \'\'' ;;
    string_long) type=string_textarea; settings=$'rows: 3\nplaceholder: \'\'' ;;
    integer|decimal) type=number; settings="placeholder: ''" ;;
    boolean) type=boolean_checkbox; settings='display_label: true' ;;
    date|daterange) type=${kind/date/datetime}_default; [[ $kind == date ]] && type=datetime_default; [[ $kind == daterange ]] && type=daterange_default ;;
    email) type=email_default; settings=$'placeholder: \'\'\nsize: 60' ;;
    link) type=link_default; settings=$'placeholder_url: \'\'\nplaceholder_title: \'\'' ;;
    ref_term|list) type=options_select ;;
    ref_node|ref_user) type=entity_reference_autocomplete; settings=$'match_operator: CONTAINS\nmatch_limit: 10\nsize: 60\nplaceholder: \'\'' ;;
    ref_media) type=media_library_widget; settings='media_types: {  }' ;;
    registration) type=registration_type ;;
  esac
  emit_component "$type" "$weight" "$indent" "$settings" ""
}

formatter_yaml() {
  local kind=$1 weight=$2 indent=$3 type settings='' label=above
  case "$kind" in
    body) type=text_default; label=hidden ;;
    string|string_long) type=basic_string; [[ $kind == string ]] && { type=string; settings='link_to_entity: false'; } ;;
    integer) type=number_integer; settings=$'thousand_separator: \'\'\nprefix_suffix: true' ;;
    decimal) type=number_decimal; settings=$'thousand_separator: \',\'\ndecimal_separator: .\nscale: 2\nprefix_suffix: true' ;;
    boolean) type=boolean; settings=$'format: default\nformat_custom_false: \'\'\nformat_custom_true: \'\'' ;;
    date) type=datetime_custom; settings=$'timezone_override: \'\'\ndate_format: \'F j, Y\'' ;;
    daterange) type=daterange_custom; settings=$'timezone_override: \'\'\ndate_format: \'D, M j, Y g:i A\'\nseparator: \'-\'\nfrom_to: both' ;;
    email) type=email_mailto ;;
    link) type=link; settings=$'trim_length: 80\nurl_only: false\nurl_plain: false\nrel: \'\'\ntarget: \'\'' ;;
    ref_term|ref_node|ref_user) type=entity_reference_label; settings='link: true' ;;
    ref_media) type=entity_reference_entity_view; settings=$'view_mode: default\nlink: false' ;;
    list) type=list_default ;;
    registration) type=registration_link; settings="label: ''"; label=hidden ;;
  esac
  emit_component "$type" "$weight" "$indent" "$settings" "$label"
}

display_module_deps() {
  local mode=$1; shift
  local -A mods=()
  local k
  for k in "$@"; do
    case "$k" in
      body) mods[text]=1 ;;
      date) mods[datetime]=1 ;;
      daterange) mods[datetime_range]=1 ;;
      link) mods[link]=1 ;;
      list) mods[options]=1 ;;
      ref_term) [[ $mode == form ]] && mods[options]=1 ;;
      ref_media) [[ $mode == form ]] && mods[media_library]=1 ;;
      registration) mods[registration]=1 ;;
    esac
  done
  if [[ $mode == form ]]; then mods[path]=1; else mods[user]=1; fi
  printf '%s\n' "${!mods[@]}" | sort | sed 's/^/    - /'
}

# Full form + view displays for a NEW node type.
node_displays() {
  local bundle=$1
  local -a comps=() kinds=()
  read -r -a comps <<< "${BUNDLE_FIELDS["node.$bundle"]}"
  local cfgdeps="" c f k w
  for c in "${comps[@]}"; do
    IFS=: read -r f k <<< "$c"
    kinds+=("$k")
    cfgdeps+="    - field.field.node.$bundle.$f"$'\n'
  done
  cfgdeps+="    - node.type.$bundle"

  {
    printf 'langcode: en\nstatus: true\ndependencies:\n  config:\n%s\n  module:\n' "$cfgdeps"
    display_module_deps form "${kinds[@]}"
    cat <<EOF
id: node.$bundle.default
targetEntityType: node
bundle: $bundle
mode: default
content:
  title:
    type: string_textfield
    weight: -5
    region: content
    settings:
      size: 60
      placeholder: ''
    third_party_settings: {  }
EOF
    w=0
    for c in "${comps[@]}"; do
      IFS=: read -r f k <<< "$c"
      echo "  $f:"; widget_yaml "$k" "$w" 4; w=$((w + 1))
    done
    cat <<'EOF'
  created:
    type: datetime_timestamp
    weight: 90
    region: content
    settings: {  }
    third_party_settings: {  }
  path:
    type: path
    weight: 91
    region: content
    settings: {  }
    third_party_settings: {  }
  uid:
    type: entity_reference_autocomplete
    weight: 92
    region: content
    settings:
      match_operator: CONTAINS
      match_limit: 10
      size: 60
      placeholder: ''
    third_party_settings: {  }
  status:
    type: boolean_checkbox
    weight: 120
    region: content
    settings:
      display_label: true
    third_party_settings: {  }
hidden:
  promote: true
  sticky: true
EOF
  } > "$CFG/core.entity_form_display.node.$bundle.default.yml"

  {
    printf 'langcode: en\nstatus: true\ndependencies:\n  config:\n%s\n  module:\n' "$cfgdeps"
    display_module_deps view "${kinds[@]}"
    printf 'id: node.%s.default\ntargetEntityType: node\nbundle: %s\nmode: default\ncontent:\n' "$bundle" "$bundle"
    w=0
    for c in "${comps[@]}"; do
      IFS=: read -r f k <<< "$c"
      echo "  $f:"; formatter_yaml "$k" "$w" 4; w=$((w + 1))
    done
    printf '  links:\n    weight: 100\n    region: content\nhidden: {  }\n'
  } > "$CFG/core.entity_view_display.node.$bundle.default.yml"
}

# setComponents action entries for an EXISTING display (used in recipe.yml).
display_action_components() {
  local key=$1 mode=$2 start=${3:-20}
  local -a comps=()
  read -r -a comps <<< "${BUNDLE_FIELDS[$key]}"
  local w=$start c f k
  for c in "${comps[@]}"; do
    IFS=: read -r f k <<< "$c"
    echo "        -"
    echo "          name: $f"
    echo "          options:"
    if [[ $mode == form ]]; then widget_yaml "$k" "$w" 12; else formatter_yaml "$k" "$w" 12; fi
    w=$((w + 1))
  done
}

# ═══════════════════════════════════════════════════════════════════
# CONTENT MODEL
#   Educare already provides: node types program/event/news, the
#   field_location storage (string_long), and the varbase editorial
#   workflow on program/news. Those are reused, not redefined.
# ═══════════════════════════════════════════════════════════════════

vocabulary department 'Department' 'Academic departments (e.g., Nursing, Computer Science).'
vocabulary program_level 'Program Level' 'Credential level awarded (A.A., A.S., A.A.S., Certificate, Letter of Recognition).'

node_type course 'Course' 'A catalog course entry (not a specific offering).' 'Describe the course as it appears in the catalog. Schedule specific offerings as Course Sessions.'
node_type course_session 'Course Session' 'A specific offering of a course in a semester.' 'Pick the course, semester, and professor. Registration capacity follows the Capacity field.'
node_type semester 'Semester' 'Academic term with registration windows and deadlines.' 'Enter the term dates and the registration, drop, and withdraw deadlines.'
node_type faculty_profile 'Faculty Profile' 'Faculty member biography and contact information.' 'Public directory profile for a faculty or staff member.'
node_type scholarship 'Scholarship' 'An award students may apply for.' 'Describe eligibility, the award amount, and the application deadline.'

role student 'Student' 10 'access content'
role professor 'Professor' 11 'access content' 'access user profiles'
role registrar 'Registrar' 12 'access content' 'access user profiles' 'administer users'

# Registration workflow: Enrolled / Waitlisted / Completed / Dropped / Withdrawn.
cat > "$CFG/workflows.workflow.course_registration.yml" <<'YAML'
langcode: en
status: true
dependencies:
  module:
    - registration
id: course_registration
label: 'Course registration'
type: registration
type_settings:
  states:
    enrolled:
      label: Enrolled
      description: 'Student holds a seat in the course session.'
      active: true
      canceled: false
      held: false
      show_on_form: true
      weight: 0
    waitlisted:
      label: Waitlisted
      description: 'Student is waiting for a seat; does not count against capacity.'
      active: false
      canceled: false
      held: true
      show_on_form: true
      weight: 1
    completed:
      label: Completed
      description: 'Student finished the course; the grade counts toward GPA.'
      active: true
      canceled: false
      held: false
      show_on_form: true
      weight: 2
    dropped:
      label: Dropped
      description: 'Dropped before the drop deadline; no transcript entry.'
      active: false
      canceled: true
      held: false
      show_on_form: true
      weight: 3
    withdrawn:
      label: Withdrawn
      description: 'Withdrew after the drop deadline; transcript shows W.'
      active: false
      canceled: true
      held: false
      show_on_form: true
      weight: 4
  transitions:
    enroll:
      label: Enroll
      to: enrolled
      weight: 0
      from:
        - waitlisted
    waitlist:
      label: 'Move to waitlist'
      to: waitlisted
      weight: 1
      from:
        - enrolled
    complete:
      label: Complete
      to: completed
      weight: 2
      from:
        - enrolled
    drop:
      label: Drop
      to: dropped
      weight: 3
      from:
        - enrolled
        - waitlisted
    withdraw:
      label: Withdraw
      to: withdrawn
      weight: 4
      from:
        - enrolled
  default_registration_state: enrolled
  complete_registration_state: completed
YAML

cat > "$CFG/registration.type.course_session.yml" <<'YAML'
langcode: en
status: true
dependencies:
  config:
    - workflows.workflow.course_registration
id: course_session
label: 'Course session'
workflow: course_registration
defaultState: enrolled
heldExpireTime: 0
heldExpireState: dropped
YAML

# Field storages - node.
storage node field_department ref_term
storage node field_program_level ref_term
storage node field_credits integer
storage node field_course_code string 20
storage node field_course ref_node
storage node field_semester ref_node
storage node field_professor ref_user
storage node field_date_time daterange
storage node field_capacity integer
storage node field_requires_matriculation boolean
storage node field_start_date date
storage node field_end_date date
storage node field_registration_open date
storage node field_registration_close date
storage node field_drop_deadline date
storage node field_withdraw_deadline date
storage node field_academic_year ref_term
storage node field_position string
storage node field_faculty_email email
storage node field_phone string 32
storage node field_office string
storage node field_photo ref_media
storage node field_award_amount decimal
storage node field_deadline date
storage node field_application_url link
storage node field_registration registration
# Field storages - user.
storage user field_student_type list
storage user field_student_id string 20
storage user field_program ref_node
storage user field_residency list
storage user field_enrollment_status list
storage user field_matriculation_date date
# Field storages - registration.
storage registration field_grade list

# Program (Educare's existing type).
instance node program field_department ref_term 'Department' false department
instance node program field_program_level ref_term 'Program Level' false program_level
instance node program field_credits integer 'Credits' false '' 'Credits required to complete the program.'

# Course.
body course 'Course description'
instance node course field_course_code string 'Course Code' true '' 'For example: ENG 101.'
instance node course field_credits integer 'Credits' true
instance node course field_department ref_term 'Department' true department

# Course session.
instance node course_session field_course ref_node 'Course' true course
instance node course_session field_semester ref_node 'Semester' true semester
instance node course_session field_professor ref_user 'Professor' true professor 'User account of the instructor (must have the Professor role).'
instance node course_session field_date_time daterange 'Meeting Date/Time' true
instance node course_session field_location string_long 'Location' true '' 'Campus and room, or Online.'
instance node course_session field_capacity integer 'Capacity' true '' 'Kept in sync with the registration capacity.'
instance node course_session field_requires_matriculation boolean 'Restricted to Matriculated' false '' 'Only matriculated students may self-register.'
instance node course_session field_registration registration 'Registration' false course_session

# Semester.
instance node semester field_start_date date 'Start Date' true
instance node semester field_end_date date 'End Date' true
instance node semester field_registration_open date 'Registration Opens' true
instance node semester field_registration_close date 'Registration Closes' true
instance node semester field_drop_deadline date 'Drop Deadline' true
instance node semester field_withdraw_deadline date 'Withdraw Deadline' true
instance node semester field_academic_year ref_term 'Academic Year' false academic_year

# Faculty profile.
body faculty_profile 'Biography'
instance node faculty_profile field_position string 'Position' true
instance node faculty_profile field_department ref_term 'Department' true department
instance node faculty_profile field_faculty_email email 'Email' true
instance node faculty_profile field_phone string 'Phone' false
instance node faculty_profile field_office string 'Office' false
instance node faculty_profile field_photo ref_media 'Photo' false image

# Scholarship.
body scholarship 'Description and eligibility'
instance node scholarship field_award_amount decimal 'Award Amount' true
instance node scholarship field_deadline date 'Deadline' true
instance node scholarship field_department ref_term 'Department' true department
instance node scholarship field_application_url link 'Application URL' false

# User (student) fields.
instance user user field_student_type list 'Student Type' false
instance user user field_student_id string 'Student ID' false
instance user user field_program ref_node 'Program of Study' false program
instance user user field_residency list 'Residency' false '' 'Determines the tuition rate.'
instance user user field_enrollment_status list 'Enrollment Status' false '' 'Only Active students can register for courses.'
instance user user field_matriculation_date date 'Matriculation Date' false '' 'Date the student was admitted to a program.'

# Registration grade.
instance registration course_session field_grade list 'Grade' false '' 'Final grade. Only Completed registrations with grades A-F count toward GPA.'

for b in course course_session semester faculty_profile scholarship; do
  node_displays "$b"
done

# ═══════════════════════════════════════════════════════════════════
# VIEWS: public table listings with an exposed department filter.
# table_view ID LABEL PATH BUNDLE DEFAULT_SORT "field|Label|formatter|settings_json" ...
# ═══════════════════════════════════════════════════════════════════
table_view() {
  local id=$1 label=$2 path=$3 bundle=$4 sort=$5; shift 5
  local specs=("$@") spec f l fmt s deps="" cols="" info=""
  for spec in "${specs[@]}"; do
    IFS='|' read -r f l fmt s <<< "$spec"
    [[ $f != title ]] && deps+="    - field.storage.node.$f"$'\n'
    cols+="            $f: $f"$'\n'
    info+="            $f:"$'\n'"              sortable: true"$'\n'"              default_sort_order: asc"$'\n'"              align: ''"$'\n'"              separator: ''"$'\n'"              empty_column: false"$'\n'"              responsive: ''"$'\n'
  done
  {
    cat <<EOF
langcode: en
status: true
dependencies:
  config:
${deps}    - node.type.$bundle
    - taxonomy.vocabulary.department
  module:
    - node
    - taxonomy
    - user
id: $id
label: '$label'
module: views
description: '$label listing.'
tag: ''
base_table: node_field_data
base_field: nid
display:
  default:
    id: default
    display_title: Default
    display_plugin: default
    position: 0
    display_options:
      title: '$label'
      fields:
EOF
    for spec in "${specs[@]}"; do
      IFS='|' read -r f l fmt s <<< "$spec"
      local table="node__$f" csc=value
      [[ $f == title ]] && table=node_field_data
      [[ $fmt == entity_reference_label ]] && csc=target_id
      cat <<EOF
        $f:
          id: $f
          table: $table
          field: $f
          relationship: none
          group_type: group
          admin_label: ''
          entity_type: node
          entity_field: $f
          plugin_id: field
          label: '$l'
          exclude: false
          element_label_colon: false
          click_sort_column: $csc
          type: $fmt
          settings: $s
EOF
    done
    cat <<EOF
      pager:
        type: full
        options:
          items_per_page: 50
          offset: 0
      exposed_form:
        type: basic
        options:
          submit_button: Apply
          reset_button: true
          reset_button_label: Reset
          exposed_sorts_label: 'Sort by'
          expose_sort_order: true
          sort_asc_label: Asc
          sort_desc_label: Desc
      access:
        type: perm
        options:
          perm: 'access content'
      cache:
        type: tag
        options: {  }
      empty:
        area_text_custom:
          id: area_text_custom
          table: views
          field: area_text_custom
          plugin_id: text_custom
          empty: true
          content: 'No results match your search.'
      sorts:
        $sort:
          id: $sort
          table: $( [[ $sort == title ]] && echo node_field_data || echo "node__$sort" )
          field: $( [[ $sort == title ]] && echo title || echo "${sort}_value" )
          plugin_id: $( [[ $sort == title ]] && echo standard || echo datetime )
          order: ASC
          exposed: false
      filters:
        status:
          id: status
          table: node_field_data
          field: status
          entity_type: node
          entity_field: status
          plugin_id: boolean
          value: '1'
          group: 1
          expose:
            operator: ''
        type:
          id: type
          table: node_field_data
          field: type
          entity_type: node
          entity_field: type
          plugin_id: bundle
          value:
            $bundle: $bundle
          group: 1
        title:
          id: title
          table: node_field_data
          field: title
          entity_type: node
          entity_field: title
          plugin_id: string
          operator: contains
          value: ''
          group: 1
          exposed: true
          expose:
            operator_id: title_op
            label: 'Search'
            identifier: search
            required: false
            remember: false
            multiple: false
            remember_roles:
              authenticated: authenticated
        field_department_target_id:
          id: field_department_target_id
          table: node__field_department
          field: field_department_target_id
          plugin_id: taxonomy_index_tid
          operator: or
          value: {  }
          group: 1
          exposed: true
          expose:
            operator_id: field_department_target_id_op
            label: Department
            identifier: department
            required: false
            remember: false
            multiple: false
            remember_roles:
              authenticated: authenticated
            reduce: false
          type: select
          limit: true
          vid: department
          hierarchy: false
          error_message: true
      style:
        type: table
        options:
          grouping: {  }
          row_class: ''
          default_row_class: true
          columns:
${cols}          default: '-1'
          info:
${info}          override: true
          sticky: true
          summary: ''
          empty_table: false
          caption: ''
          description: ''
      row:
        type: fields
      query:
        type: views_query
        options:
          query_comment: ''
          disable_sql_rewrite: false
          distinct: false
          replica: false
          query_tags: {  }
      display_extenders: {  }
  page_1:
    id: page_1
    display_title: Page
    display_plugin: page
    position: 1
    display_options:
      path: $path
      display_extenders: {  }
EOF
  } > "$CFG/views.view.$id.yml"
}

table_view faculty 'Faculty Directory' faculty faculty_profile title \
  'title|Name|string|{ link_to_entity: true }' \
  'field_position|Position|string|{ link_to_entity: false }' \
  'field_department|Department|entity_reference_label|{ link: false }' \
  'field_faculty_email|Email|email_mailto|{  }' \
  'field_phone|Phone|string|{ link_to_entity: false }' \
  'field_office|Office|string|{ link_to_entity: false }'

table_view scholarships 'Scholarships' scholarships scholarship field_deadline \
  'title|Scholarship|string|{ link_to_entity: true }' \
  'field_department|Department|entity_reference_label|{ link: false }' \
  'field_award_amount|Award|number_decimal|{ thousand_separator: '"','"', decimal_separator: ., scale: 2, prefix_suffix: true }' \
  'field_deadline|Deadline|datetime_custom|{ timezone_override: '"''"', date_format: '"'M j, Y'"' }'

# ═══════════════════════════════════════════════════════════════════
# recipe.yml
# ═══════════════════════════════════════════════════════════════════
{
  cat <<'YAML'
name: 'Montgomery Community College Academic Structure'
description: 'Content types, fields, vocabularies, registration workflow, grades, and roles for a community college.'
type: 'Site'
install:
  - datetime
  - datetime_range
  - field
  - link
  - media
  - media_library
  - menu_ui
  - node
  - options
  - path
  - registration
  - taxonomy
  - text
  - user
  - workflows
  - mc_college_core
config:
  strict: false
  import:
    node:
      - field.storage.node.body
  actions:
    # Varbase uses Taxonomy Access Fix: each vocabulary needs explicit grants.
    user.role.anonymous: &public_terms
      grantPermissions:
        - 'view terms in department'
        - 'view term names in department'
        - 'view terms in program_level'
        - 'view term names in program_level'
        - 'view terms in academic_year'
        - 'view term names in academic_year'
    user.role.authenticated: *public_terms
    # Educare's program page shows badges bound to the first Program Type and
    # Study Level; an empty value breaks rendering, so require both.
    field.field.node.program.field_program_type:
      setRequired: true
    field.field.node.program.field_study_level:
      setRequired: true
    user.role.student:
      grantPermissions:
        - 'view own course_session registration'
        - 'create course_session registration self'
        - 'view own academic record'
        - 'manage own course registrations'
    user.role.professor:
      grantPermissions:
        - 'grade own course sessions'
    user.role.registrar:
      grantPermissions:
        - 'view student academic records'
        - 'view college performance analytics'
        - 'administer course_session registration'
        - 'administer course_session registration settings'
        - 'edit course_session registration state'
        - 'view any course_session registration'
        - 'update any course_session registration'
        - 'delete any course_session registration'
        - 'access commerce_order overview'
        - 'administer commerce_order'
        - 'view commerce_order'
    core.entity_form_display.node.program.default:
      setComponents:
YAML
  display_action_components node.program form
  # Program pages are rendered by Educare's Canvas content template, so the
  # new program fields are added to the edit form only.
  for mode in form view; do
    echo "    core.entity_${mode}_display.user.user.default:"
    echo "      createIfNotExists:"
    echo "        targetEntityType: user"
    echo "        bundle: user"
    echo "        mode: default"
    echo "        status: true"
    echo "      setComponents:"
    display_action_components user.user "$mode"
    if [[ $mode == view ]]; then
      cat <<'YAML'
        -
          name: field_gpa
          options:
            type: number_decimal
            label: inline
            settings:
              thousand_separator: ''
              decimal_separator: .
              scale: 2
              prefix_suffix: false
            third_party_settings: {  }
            weight: 30
            region: content
YAML
    fi
    echo "    core.entity_${mode}_display.registration.course_session.default:"
    echo "      createIfNotExists:"
    echo "        targetEntityType: registration"
    echo "        bundle: course_session"
    echo "        mode: default"
    echo "        status: true"
    echo "      setComponents:"
    display_action_components registration.course_session "$mode"
  done
} > "$RECIPE/recipe.yml"

# ═══════════════════════════════════════════════════════════════════
# THEME: generated from the Educare starterkit (Varbase-supported path;
# auto_switch_components migrates Canvas regions when it becomes default).
# ═══════════════════════════════════════════════════════════════════
if [[ ! -f "$THEME/mc_college.info.yml" ]]; then
  echo "→ Generating mc_college theme from the vartheme_bs5_educare starterkit"
  if command -v ddev >/dev/null 2>&1; then
    (cd "$ROOT" && ddev exec -d /var/www/html/web php core/scripts/drupal generate-theme mc_college \
      --starterkit vartheme_bs5_educare --name "MC College" \
      --description "Montgomery Community College theme, generated from Vartheme BS5 Educare." \
      --path themes/custom)
  else
    (cd "$ROOT/web" && php core/scripts/drupal generate-theme mc_college \
      --starterkit vartheme_bs5_educare --name "MC College" \
      --description "Montgomery Community College theme, generated from Vartheme BS5 Educare." \
      --path themes/custom)
  fi
fi

mkdir -p "$THEME/css"
cat > "$THEME/css/mc-college.css" <<'CSS'
/**
 * @file
 * Montgomery Community College brand layer on top of the Educare design system.
 */
:root {
  --mc-purple: #51237f;
  --mc-purple-dark: #3e1a62;
  --mc-blue: #005a9c;
  --mc-success: #1e7d34;
  --bs-primary: var(--mc-purple);
  --bs-primary-rgb: 81, 35, 127;
  --bs-link-color: var(--mc-blue);
  --bs-link-color-rgb: 0, 90, 156;
  --bs-link-hover-color: var(--mc-purple);
}
.bg-primary { background-color: var(--mc-purple) !important; }
.text-primary { color: var(--mc-purple) !important; }
.btn-primary {
  --bs-btn-bg: var(--mc-purple);
  --bs-btn-border-color: var(--mc-purple);
  --bs-btn-hover-bg: var(--mc-purple-dark);
  --bs-btn-hover-border-color: var(--mc-purple-dark);
  --bs-btn-active-bg: var(--mc-purple-dark);
}

/* College content types (classes added by mc_college_core). */
.mc-node .field__label { font-weight: 700; color: var(--mc-purple); }
.mc-node--course .field--name-field-course-code .field__item {
  display: inline-block;
  padding: 0.15rem 0.6rem;
  border-radius: 0.25rem;
  background: var(--mc-purple);
  color: #fff;
  font-weight: 700;
}
.mc-node--course-session .field,
.mc-node--semester .field {
  display: grid;
  grid-template-columns: minmax(10rem, 14rem) 1fr;
  gap: 0.5rem;
  padding-block: 0.5rem;
  border-block-end: 1px solid #e3e6eb;
}
.mc-node--scholarship .field--name-field-award-amount .field__item {
  color: var(--mc-success);
  font-size: 1.5rem;
  font-weight: 700;
}
.mc-registration-status {
  display: inline-block;
  margin-block-end: 1rem;
  padding: 0.35rem 0.9rem;
  border-radius: 999px;
  font-weight: 700;
}
.mc-registration-status--open { background: #e3f4e7; color: var(--mc-success); }
.mc-registration-status--upcoming { background: #fff6db; color: #7a5b00; }
.mc-registration-status--closed { background: #eceef1; color: #5b6472; }
CSS

# Register the brand library once (idempotent).
if ! grep -q '^mc-college:' "$THEME/mc_college.libraries.yml"; then
  cat >> "$THEME/mc_college.libraries.yml" <<'YAML'

mc-college:
  version: 1.x
  css:
    theme:
      css/mc-college.css: {}
YAML
fi
if ! grep -q 'mc_college/mc-college' "$THEME/mc_college.info.yml"; then
  sed -i 's#^libraries:$#libraries:\n  - mc_college/mc-college#' "$THEME/mc_college.info.yml"
fi

# Upstream starterkit bug: page-title passes text_size: "" to the heading
# component, whose enum has no empty value, so pages without a display size
# (e.g. registration forms) fail component validation with HTTP 500.
PAGE_TITLE="$THEME/components/atoms/page-title/page-title.twig"
if [[ -f $PAGE_TITLE ]] && grep -q 'text_size: display,' "$PAGE_TITLE"; then
  perl -0pi -e 's/\{\{ include\(.mc_college:heading., \{\s*level: 1,\s*heading_text: title,\s*text_size: display,\s*attributes: _title_attributes,\s*\}\s*, false\) \}\}/{% set heading_props = {level: 1, heading_text: title, attributes: _title_attributes} %}\n  {% if display %}{% set heading_props = heading_props|merge({text_size: display}) %}{% endif %}\n  {{ include(\x27mc_college:heading\x27, heading_props, false) }}/s' "$PAGE_TITLE"
fi

# Keep the custom recipe in version control (Composer-managed recipes stay ignored).
if [[ -f "$ROOT/.gitignore" ]] && grep -qx '/recipes/' "$ROOT/.gitignore"; then
  sed -i 's#^/recipes/$#/recipes/*\n!/recipes/mc_college_structure/#' "$ROOT/.gitignore"
fi

echo "✅ Blueprint files written."
echo "→ Recipe:  $RECIPE/recipe.yml ($(find "$CFG" -name '*.yml' | wc -l) config files)"
echo "→ Module:  $MODULE"
echo "→ Theme:   $THEME"
echo "→ Seed:    $SCRIPTS/mc_seed.php"
