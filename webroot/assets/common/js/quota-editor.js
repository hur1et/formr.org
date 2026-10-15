/* Quota draft editor. The server validates the same definition before saving. */
function element(tag, text, attrs = {}) {
  const node = document.createElement(tag);
  if (text !== null) node.textContent = text;
  Object.entries(attrs).forEach(([key, value]) => node.setAttribute(key, value));
  return node;
}

function integer(value) {
  const text = String(value);
  if (!/^-?(0|[1-9][0-9]*)$/.test(text) || !Number.isSafeInteger(Number(text))) {
    throw new Error("Enter whole numbers without decimals or rounding.");
  }
  return Number(text);
}

export function quotaGroups(dimensions) {
  if (!dimensions.length) throw new Error("Select at least one variable per table.");
  let groups = [{ code: [], labels: [] }];
  const sources = new Set();
  dimensions.forEach((dim) => {
    if (sources.has(dim.source)) throw new Error("Select each variable once per table.");
    sources.add(dim.source);
    const entries = Object.entries(dim.categories);
    if (!entries.length) throw new Error("Select categories or add integer ranges.");
    if (dim.type === "integer") {
      const ranges = entries.map(([, range]) => ({ min: integer(range.min), max: integer(range.max) }));
      ranges.forEach((range, i) => {
        if (range.min > range.max) throw new Error("A range's minimum must not exceed its maximum.");
        ranges.slice(i + 1).forEach((other) => {
          if (range.min <= other.max && other.min <= range.max) throw new Error("Integer ranges must not overlap.");
        });
      });
    }
    groups = groups.flatMap((group) => entries.map(([code, value]) => ({
      code: [...group.code, code], labels: [...group.labels, dim.type === "integer" ? `${value.min}–${value.max}` : String(value)],
    })));
  });
  return groups.map((group) => ({ code: group.code.join("|"), labels: group.labels }));
}

export function initQuotaEditor(block) {
  const root = block.querySelector(".quota-editor");
  if (!root) return null;
  const sources = JSON.parse(root.dataset.sources);
  const hidden = root.querySelector("[name=quota_tables]");
  const error = root.querySelector(".quota-editor-error");
  const saved = JSON.parse(hidden.value);
  const tables = Object.entries(saved).map(([code, table]) => ({ code, ...table }));
  const container = root.querySelector(".quota-editor-tables");
  const fail = (message) => { error.textContent = message; error.hidden = false; };
  function sync() {
    try {
      if (!tables.length) throw new Error("Add at least one quota table.");
      const output = Object.create(null);
      tables.forEach((table) => {
        if (!/^[A-Za-z0-9_-]{1,64}$/.test(table.code) || Object.hasOwn(output, table.code)) {
          throw new Error("Give every table a unique code using letters, numbers, underscores or hyphens.");
        }
        table.dimensions.forEach((dim) => {
          if (!sources[dim.source]?.supported) throw new Error("Replace unavailable or unsupported variables before saving.");
        });
        const dimensions = table.dimensions.map((dim) => ({ ...dim, categories: dim.type === "integer"
          ? Object.fromEntries(Object.entries(dim.categories).map(([code, range]) => [code, { min: integer(range.min), max: integer(range.max) }]))
          : dim.categories }));
        const limits = Object.create(null);
        quotaGroups(dimensions).forEach((group) => {
          const limit = integer(table.limits[group.code] ?? 0);
          if (limit < 0 || limit > 4294967295) throw new Error("Limits must be nonnegative whole numbers up to 4294967295.");
          limits[group.code] = limit;
        });
        output[table.code] = { dimensions, limits };
      });
      hidden.value = JSON.stringify(output);
      error.hidden = true;
      return true;
    } catch (exception) { fail(exception.message); return false; }
  }
  function changed() {
    sync();
    hidden.dispatchEvent(new Event("change", { bubbles: true }));
  }
  function button(text, action) {
    const node = element("button", text, { type: "button", class: "btn btn-default btn-sm" });
    node.addEventListener("click", action);
    return node;
  }
  function input(label, value, action, numeric = false) {
    const wrapper = element("label", label + " ");
    const node = element("input", null, { class: "form-control", type: numeric ? "number" : "text", ...(numeric ? { step: "1" } : {}) });
    node.value = value ?? "";
    const update = () => { action(node.value); changed(); };
    node.addEventListener("input", update);
    node.addEventListener("change", update);
    wrapper.append(node);
    return wrapper;
  }
  function renderGroups(table, target) {
    target.replaceChildren();
    try {
      const dimensions = table.dimensions.map((dim) => ({ ...dim, categories: dim.type === "integer"
        ? Object.fromEntries(Object.entries(dim.categories).map(([code, range]) => [code, { min: integer(range.min), max: integer(range.max) }])) : dim.categories }));
      const grid = element("table", null, { class: "table table-striped" });
      const thead = element("thead", null);
      const head = element("tr", null);
      ["Group code", "Quota group", "Limit (0 = closed)"].forEach((title) => head.append(element("th", title, { scope: "col" })));
      thead.append(head); grid.append(thead);
      const tbody = element("tbody", null); grid.append(tbody);
      const meanings = Object.create(null);
      const limits = Object.create(null);
      quotaGroups(dimensions).forEach((group) => {
        const categoryCodes = group.code.split("|");
        const meaning = JSON.stringify(dimensions.map((dim, i) => [dim.source, dim.type, dim.categories[categoryCodes[i]]]));
        meanings[group.code] = meaning;
        limits[group.code] = !table.meanings || table.meanings[group.code] === meaning ? (table.limits[group.code] ?? 0) : 0;
        const row = element("tr", null);
        const labels = dimensions.map((dim, index) => {
          if (dim.type === "integer") return `${dim.item_name}: ${group.labels[index]}`;
          const choice = sources[dim.source]?.choices.find((entry) => String(entry.value) === group.labels[index]);
          return `${dim.item_name}: ${choice?.label || group.labels[index]} (${group.labels[index]})`;
        });
        row.append(element("td", group.code), element("td", labels.join(" × ")));
        const cell = element("td", null);
        cell.append(input("Limit for " + group.code, limits[group.code], (value) => { table.limits[group.code] = value; }, true));
        row.append(cell); tbody.append(row);
      });
      table.meanings = meanings; table.limits = limits;
      target.append(grid);
    } catch (exception) { target.append(element("p", exception.message, { class: "text-warning" })); }
  }
  function nextCode(prefix, codes) {
    let index = 1;
    while (codes.includes(prefix + index)) index++;
    return prefix + index;
  }
  function dimensionView(table, dim, groupTarget) {
    const view = element("fieldset", null, { class: "well well-sm" });
    const selectLabel = element("label", "Survey / stored variable / question ");
    const select = element("select", null, { class: "form-control" });
    Object.values(sources).forEach((source) => {
      const option = element("option", `${source.survey_label || "Survey"} [${source.run_unit_id}] / ${source.item_name} / ${source.label}${source.supported ? "" : " — unsupported"}`,
        { value: source.source });
      option.disabled = !source.supported;
      select.append(option);
    });
    if (!sources[dim.source]) select.append(element("option", "Source unavailable: " + dim.source, { value: dim.source }));
    [...select.options].forEach((option) => { option.selected = option.value === dim.source; });
    select.addEventListener("change", () => {
      const source = sources[select.value];
      Object.assign(dim, { source: source.source, run_unit_id: source.run_unit_id, item_name: source.item_name,
        type: source.type, categories: {} });
      render(); changed();
    });
    selectLabel.append(select); view.append(selectLabel);
    const source = sources[dim.source];
    if (!source?.supported) view.append(element("p", source?.warning || "This source is no longer available.", { class: "text-danger" }));
    if (dim.type === "category") {
      view.append(element("p", "Select permitted answer codes. Leave missing-answer codes unselected."));
      (source?.choices || []).forEach((choice) => {
        const wrapper = element("div", null, { class: "checkbox" });
        const label = element("label", null);
        const checkbox = element("input", null, { type: "checkbox" });
        let selectedCode = Object.keys(dim.categories).find((code) => String(dim.categories[code]) === String(choice.value));
        checkbox.checked = selectedCode !== undefined;
        checkbox.addEventListener("change", () => {
          if (checkbox.checked) {
            selectedCode ??= nextCode("c", Object.keys(dim.categories));
            dim.categories[selectedCode] = String(choice.value);
          } else delete dim.categories[selectedCode];
          renderGroups(table, groupTarget); changed();
        });
        label.append(checkbox, document.createTextNode(`${choice.value} = ${choice.label || choice.value}`));
        wrapper.append(label); view.append(wrapper);
      });
    } else if (dim.type === "integer") {
      view.append(element("p", "Integer ranges include both boundaries. The original answer is preserved."));
      Object.entries(dim.categories).forEach(([code, range]) => {
        const row = element("div", null);
        row.append(element("span", code + ": "),
          input("From", range.min, (value) => { range.min = value; renderGroups(table, groupTarget); }, true),
          input("To", range.max, (value) => { range.max = value; renderGroups(table, groupTarget); }, true),
          button("Remove range", () => { delete dim.categories[code]; render(); changed(); }));
        view.append(row);
      });
      view.append(button("Add integer range", () => {
        dim.categories[nextCode("g", Object.keys(dim.categories))] = { min: "", max: "" };
        render(); changed();
      }));
    }
    view.append(button("Remove variable", () => { table.dimensions.splice(table.dimensions.indexOf(dim), 1); render(); changed(); }));
    return view;
  }
  function render() {
    container.replaceChildren();
    tables.forEach((table) => {
      const panel = element("section", null, { class: "panel panel-default" });
      const body = element("div", null, { class: "panel-body" });
      body.append(input("Table code", table.code, (value) => { table.code = value; }));
      const groupTarget = element("div", null, { class: "quota-groups table-responsive" });
      table.dimensions.forEach((dim) => body.append(dimensionView(table, dim, groupTarget)));
      const add = button("Add variable", () => {
        const source = Object.values(sources).find((candidate) => candidate.supported
          && !table.dimensions.some((dim) => dim.source === candidate.source));
        if (!source) { fail("No further supported variables are available before this Quota unit."); return; }
        table.dimensions.push({ source: source.source, run_unit_id: source.run_unit_id, item_name: source.item_name,
          type: source.type, categories: {} });
        render(); changed();
      });
      body.append(add, button("Remove table", () => { tables.splice(tables.indexOf(table), 1); render(); changed(); }));
      renderGroups(table, groupTarget); body.append(groupTarget); panel.append(body); container.append(panel);
    });
  }
  root.querySelector(".quota-add-table").addEventListener("click", () => {
    tables.push({ code: nextCode("table_", tables.map((table) => table.code)), dimensions: [], limits: {} });
    render(); changed();
  });
  render();
  return { sync };
}
