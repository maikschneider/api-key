// Shows only the user field that matches the selected identity of an API key.
// Changing the identity must not reload FormEngine, since a reload saves the record.
export default class IdentitySelect {
  constructor(selectId) {
    const select = document.getElementById(selectId);
    if (select === null) {
      return;
    }
    const fields = JSON.parse(select.dataset.identityFields);
    const toggle = () => {
      for (const [identity, name] of Object.entries(fields)) {
        const field = select.form?.querySelector(`[name="${CSS.escape(name)}"]`);
        const container = field?.closest('.t3js-formengine-palette-field, .form-group');
        if (container) {
          container.hidden = identity !== select.value;
        }
      }
    };
    select.addEventListener('change', toggle);
    toggle();
  }
}
