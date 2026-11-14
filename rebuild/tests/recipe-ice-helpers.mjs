import assert from "node:assert/strict";
import {
  SIZE_OUNCES,
  isIceIngredient,
  findActiveIceIngredient,
  calculateIceQuantity,
  recipeQtyPresetsForRow,
  defaultRecipeQtyForIngredient,
  applyCupSizeIceToRows,
  copyRecipeRowsForVariant,
} from "../src/recipe-helpers.js";

const iceG = { id: 10, name: "Ice Cubes", unit: "g", is_active: 1 };
const iceInactive = { id: 11, name: "Crushed Ice", unit: "g", is_active: 0 };
const iceUnits = { id: 12, name: "Tube Ice", unit: "units", is_active: 1 };
const spice = { id: 13, name: "Pumpkin Spice", unit: "g", is_active: 1 };
const milk = { id: 14, name: "Whole Milk", unit: "mL", is_active: 1 };
const cup = { id: 15, name: "Iced Cup", unit: "units", is_active: 1 };

const ingredients = [iceInactive, spice, iceG, milk, cup];

// 1. Ice ingredient detection ignores false positives like "Spice"
assert.equal(isIceIngredient(iceG), true);
assert.equal(isIceIngredient(iceUnits), true);
assert.equal(isIceIngredient(spice), false);
assert.equal(isIceIngredient(milk), false);
assert.equal(findActiveIceIngredient(ingredients)?.id, iceG.id);

// 2. Cup-size proportional scaling across S (12oz), M (16oz), L (22oz)
assert.deepEqual(SIZE_OUNCES, { S: 12, M: 16, L: 22 });
assert.equal(calculateIceQuantity("S", "g", "light"), 90);
assert.equal(calculateIceQuantity("M", "g", "light"), 120);
assert.equal(calculateIceQuantity("L", "g", "light"), 165);
assert.equal(calculateIceQuantity("S", "g", "regular"), 120);
assert.equal(calculateIceQuantity("M", "g", "regular"), 160);
assert.equal(calculateIceQuantity("L", "g", "regular"), 220);
assert.equal(calculateIceQuantity("S", "mL", "extra"), 150);
assert.equal(calculateIceQuantity("M", "mL", "extra"), 200);
assert.equal(calculateIceQuantity("L", "mL", "extra"), 275);
assert.equal(calculateIceQuantity("S", "g", "packed"), 180);
assert.equal(calculateIceQuantity("M", "g", "packed"), 240);
assert.equal(calculateIceQuantity("L", "g", "packed"), 330);

// 3. Custom flexible rate per oz scales cleanly across cup sizes
assert.equal(calculateIceQuantity("S", "g", "custom", "8.5"), 102);
assert.equal(calculateIceQuantity("M", "g", "custom", "8.5"), 136);
assert.equal(calculateIceQuantity("L", "g", "custom", "8.5"), 187);
// Invalid or negative custom rate gracefully falls back to preset
assert.equal(calculateIceQuantity("M", "g", "regular", "-5"), 160);
assert.equal(calculateIceQuantity("M", "g", "regular", "abc"), 160);

// 4. Whole units constraint preserved when ice is tracked in discrete units
assert.equal(calculateIceQuantity("S", "units", "regular"), 1);
assert.equal(calculateIceQuantity("M", "units", "regular"), 2);
assert.equal(calculateIceQuantity("L", "units", "extra"), 3);

// 5. Row presets & default quantity adapt to cup size and temperature
const varIcedL = { id: 1, product_id: 1, size: "L", temperature: "ICED", sku: "L-I" };
const varHotM = { id: 2, product_id: 1, size: "M", temperature: "HOT", sku: "M-H" };
const varIcedM = { id: 3, product_id: 1, size: "M", temperature: "ICED", sku: "M-I" };
const varIcedS = { id: 4, product_id: 1, size: "S", temperature: "ICED", sku: "S-I" };

assert.equal(
  defaultRecipeQtyForIngredient({ ingredient: iceG, variant: varIcedL }),
  "220",
);
assert.equal(
  defaultRecipeQtyForIngredient({ ingredient: iceG, variant: varHotM }),
  "",
);
assert.equal(
  defaultRecipeQtyForIngredient({ ingredient: cup, variant: varIcedS }),
  "1",
);

const rowPresets = recipeQtyPresetsForRow({
  ingredient: iceG,
  variant: varIcedS,
  customIcePerOz: "8.5",
});
assert.deepEqual(
  rowPresets.map((p) => p.qty),
  ["90", "120", "150", "180", "102"],
);

// 6. applyCupSizeIceToRows appends ice when absent and updates in place without duplicating
const firstApply = applyCupSizeIceToRows({
  rows: [{ ingredient_id: String(milk.id), qty: "180" }],
  targetVar: varIcedM,
  ingredients,
  levelKey: "extra",
});
assert.equal(firstApply.rows.length, 2);
assert.equal(firstApply.updatedQty, "200");

const secondApply = applyCupSizeIceToRows({
  rows: firstApply.rows,
  targetVar: varIcedL,
  ingredients,
  levelKey: "custom",
  customPerOz: "9",
});
assert.equal(secondApply.rows.length, 2);
assert.equal(secondApply.rows[1].qty, "198");

// 7. copyRecipeRowsForVariant scales M->L, adds cup-size ice for HOT->ICED, omits ice for ICED->HOT
const hotToIced = copyRecipeRowsForVariant({
  sourceRows: [
    { ingredient_id: String(milk.id), qty: "160" },
    { ingredient_id: String(cup.id), qty: "1" },
  ],
  sourceVar: varHotM,
  targetVar: varIcedL,
  ingredients,
  scaleBySize: true,
});
assert.equal(hotToIced.rows.find((r) => r.ingredient_id === String(milk.id))?.qty, "220");
assert.equal(hotToIced.rows.find((r) => r.ingredient_id === String(cup.id))?.qty, "1");
assert.equal(hotToIced.rows.find((r) => r.ingredient_id === String(iceG.id))?.qty, "220");
assert.ok(hotToIced.addedIce);

const icedToHot = copyRecipeRowsForVariant({
  sourceRows: hotToIced.rows,
  sourceVar: varIcedL,
  targetVar: varHotM,
  ingredients,
  scaleBySize: true,
});
assert.equal(icedToHot.strippedIce, true);
assert.equal(
  icedToHot.rows.some((r) => r.ingredient_id === String(iceG.id)),
  false,
);

console.log(
  "PASS all cup-size flexible ice recipe tests (presets, custom/oz scaling, units rounding, HOT<->ICED copy adaptation)",
);
