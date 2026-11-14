/**
 * Cup-size scaling and flexible ice calculation helpers for Management Recipes.
 */

export const SIZE_OUNCES = { S: 12, M: 16, L: 22 };

export const ICE_FILL_LEVELS = [
  { key: "light", label: "Light", factorPerOz: 7.5, unitsBySize: { S: 1, M: 1, L: 1 } },
  { key: "regular", label: "Regular", factorPerOz: 10, unitsBySize: { S: 1, M: 2, L: 2 } },
  { key: "extra", label: "Extra", factorPerOz: 12.5, unitsBySize: { S: 2, M: 2, L: 3 } },
  { key: "packed", label: "Packed", factorPerOz: 15, unitsBySize: { S: 2, M: 3, L: 3 } },
];

const DEFAULT_NON_ICE_PRESETS = ["15", "20", "30", "40", "150", "200", "250"];
const DEFAULT_UNIT_PRESETS = ["1", "2", "3"];

/**
 * Return nominal cup volume in fluid ounces for a variant size code.
 */
export function variantCupOunces(variant) {
  return SIZE_OUNCES[variant?.size] || 16;
}

/**
 * Detect whether an ingredient represents ice (e.g. Ice, Ice Cubes, Crushed Ice).
 */
export function isIceIngredient(ingredient) {
  return /(\bice\b|ice\s*cube|tube\s*ice|crushed\s*ice)/i.test(
    String(ingredient?.name || ""),
  );
}

/**
 * Find the best active Ice ingredient from the café ingredient catalogue.
 */
export function findActiveIceIngredient(ingredients = []) {
  const active = ingredients.filter(
    (i) => Number(i.is_active) && isIceIngredient(i),
  );
  if (!active.length) return null;
  const exact = active.find((i) =>
    /^(ice|ice cubes?)$/i.test(String(i.name || "").trim()),
  );
  return exact || active[0];
}

/**
 * Parse a positive custom ice-per-oz factor, or return null when empty/invalid.
 */
export function parseCustomIceRate(customPerOz) {
  if (customPerOz === null || customPerOz === undefined) return null;
  const trimmed = String(customPerOz).trim();
  if (!trimmed) return null;
  const parsed = Number(trimmed);
  if (!Number.isFinite(parsed) || parsed <= 0 || parsed > 100) return null;
  return parsed;
}

/**
 * Compute recipe ice quantity from cup size (S/M/L), unit (g/mL/units), fill level, or custom oz rate.
 */
export function calculateIceQuantity(
  size,
  unit = "g",
  levelKey = "regular",
  customPerOz = null,
) {
  const oz = SIZE_OUNCES[size] || 16;
  const cleanUnit = String(unit || "g").toLowerCase();
  const level =
    ICE_FILL_LEVELS.find((l) => l.key === levelKey) || ICE_FILL_LEVELS[1];
  const customRate = parseCustomIceRate(customPerOz);

  if (cleanUnit === "units") {
    if (customRate !== null) {
      return Math.max(1, Math.round(oz * customRate));
    }
    return level.unitsBySize[size] ?? 1;
  }

  const factor = customRate !== null ? customRate : level.factorPerOz;
  const raw = oz * factor;
  const rounded = raw >= 5 ? Math.round(raw) : Math.round(raw * 100) / 100;
  return Math.max(0.001, rounded);
}

/**
 * Build dynamic quick-select preset chips for a recipe ingredient row.
 */
export function recipeQtyPresetsForRow({
  ingredient,
  variant,
  customIcePerOz = "",
}) {
  const unit = String(ingredient?.unit || "").toLowerCase();
  if (isIceIngredient(ingredient) && variant) {
    const chips = ICE_FILL_LEVELS.map((lv) => {
      const qty = calculateIceQuantity(variant.size, ingredient.unit, lv.key);
      return {
        key: lv.key,
        label: `${lv.label} ${qty}`,
        qty: String(qty),
      };
    });
    const customQty =
      parseCustomIceRate(customIcePerOz) !== null
        ? calculateIceQuantity(
            variant.size,
            ingredient.unit,
            "custom",
            customIcePerOz,
          )
        : null;
    if (customQty !== null) {
      chips.push({
        key: "custom",
        label: `Custom ${customQty}`,
        qty: String(customQty),
      });
    }
    return chips;
  }

  const list =
    unit === "units" ? DEFAULT_UNIT_PRESETS : DEFAULT_NON_ICE_PRESETS;
  return list.map((q) => ({ key: q, label: q, qty: q }));
}

/**
 * Suggest default recipe quantity when selecting an ingredient in a variant recipe row.
 */
export function defaultRecipeQtyForIngredient({
  ingredient,
  variant,
  customIcePerOz = "",
}) {
  const unit = String(ingredient?.unit || "").toLowerCase();
  if (isIceIngredient(ingredient) && variant?.temperature === "ICED") {
    return String(
      calculateIceQuantity(
        variant.size,
        ingredient.unit,
        "regular",
        customIcePerOz,
      ),
    );
  }
  if (unit === "units") {
    return "1";
  }
  return "";
}

/**
 * Update an existing Ice row in `rows` or append active Ice ingredient sized to `targetVar`.
 */
export function applyCupSizeIceToRows({
  rows = [],
  targetVar,
  ingredients = [],
  levelKey = "regular",
  customPerOz = "",
}) {
  if (!targetVar) return { rows, iceIngredient: null, updatedQty: null };
  const ingMap = new Map(ingredients.map((i) => [String(i.id), i]));
  const existingIdx = rows.findIndex((r) =>
    isIceIngredient(ingMap.get(String(r.ingredient_id))),
  );
  const iceIngredient =
    existingIdx >= 0
      ? ingMap.get(String(rows[existingIdx].ingredient_id))
      : findActiveIceIngredient(ingredients);

  if (!iceIngredient) {
    return { rows, iceIngredient: null, updatedQty: null };
  }

  const updatedQty = String(
    calculateIceQuantity(
      targetVar.size,
      iceIngredient.unit,
      levelKey,
      customPerOz,
    ),
  );

  if (existingIdx >= 0) {
    const nextRows = rows.map((r, idx) =>
      idx === existingIdx ? { ...r, qty: updatedQty } : r,
    );
    return { rows: nextRows, iceIngredient, updatedQty };
  }

  return {
    rows: [
      ...rows,
      { ingredient_id: String(iceIngredient.id), qty: updatedQty },
    ],
    iceIngredient,
    updatedQty,
  };
}

/**
 * Copy and optionally cup-scale recipe rows between variants, adapting Ice for HOT vs ICED.
 */
export function copyRecipeRowsForVariant({
  sourceRows = [],
  sourceVar,
  targetVar,
  ingredients = [],
  scaleBySize = false,
  customIcePerOz = "",
}) {
  const targetOz = variantCupOunces(targetVar);
  const sourceOz = variantCupOunces(sourceVar);
  const ratio = scaleBySize && sourceOz > 0 ? targetOz / sourceOz : 1;
  const ingMap = new Map(ingredients.map((i) => [Number(i.id), i]));

  let copied = sourceRows.map((r) => {
    const ing = ingMap.get(Number(r.ingredient_id));
    const unit = String(ing?.unit || "").toLowerCase();
    const rawQty = Number(r.qty) || 0;
    let nextQty = rawQty;
    if (scaleBySize && ratio !== 1 && unit !== "units") {
      const scaled = rawQty * ratio;
      nextQty =
        scaled >= 5 ? Math.round(scaled) : Math.round(scaled * 100) / 100;
    }
    return {
      ingredient_id: String(r.ingredient_id),
      qty: String(nextQty),
    };
  });

  let strippedIce = false;
  let addedIce = null;

  if (targetVar?.temperature === "HOT") {
    const beforeLen = copied.length;
    copied = copied.filter(
      (r) => !isIceIngredient(ingMap.get(Number(r.ingredient_id))),
    );
    strippedIce = copied.length < beforeLen;
  } else if (targetVar?.temperature === "ICED") {
    const hasIce = copied.some((r) =>
      isIceIngredient(ingMap.get(Number(r.ingredient_id))),
    );
    if (!hasIce) {
      const activeIce = findActiveIceIngredient(ingredients);
      if (activeIce) {
        const iceQty = String(
          calculateIceQuantity(
            targetVar.size,
            activeIce.unit,
            "regular",
            customIcePerOz,
          ),
        );
        copied = [
          ...copied,
          { ingredient_id: String(activeIce.id), qty: iceQty },
        ];
        addedIce = { name: activeIce.name, unit: activeIce.unit, qty: iceQty };
      }
    }
  }

  return {
    rows: copied,
    sourceOz,
    targetOz,
    scaled: scaleBySize && ratio !== 1,
    strippedIce,
    addedIce,
  };
}
