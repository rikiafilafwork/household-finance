import js from '@eslint/js';
import reactHooks from 'eslint-plugin-react-hooks';
import globals from 'globals';
import tseslint from 'typescript-eslint';

/**
 * Keputusan #1: uang = integer rupiah. Float dilarang di seluruh codebase web,
 * parsing input uang wajib lewat parseIDRInput() dari @personal-finance/shared.
 */
const noFloatMoney = [
  'error',
  { selector: "CallExpression[callee.name='parseFloat']", message: 'parseFloat dilarang (Keputusan #1). Pakai parseIDRInput() dari @personal-finance/shared.' },
  { selector: "MemberExpression[object.name='Number'][property.name='parseFloat']", message: 'Number.parseFloat dilarang (Keputusan #1). Pakai parseIDRInput().' },
  { selector: "CallExpression[callee.property.name='toFixed']", message: 'toFixed() menandakan float. Format uang lewat formatIDR()/formatThousands().' },
];

export default tseslint.config(
  { ignores: ['dist', 'node_modules', 'src/components/ui'] },
  js.configs.recommended,
  ...tseslint.configs.recommended,
  {
    files: ['**/*.{ts,tsx}'],
    languageOptions: { globals: globals.browser },
    plugins: { 'react-hooks': reactHooks },
    rules: {
      ...reactHooks.configs.recommended.rules,
      'no-restricted-syntax': noFloatMoney,
      'no-restricted-globals': ['error', { name: 'parseFloat', message: 'parseFloat dilarang (Keputusan #1).' }],
      '@typescript-eslint/no-unused-vars': ['error', { argsIgnorePattern: '^_' }],
    },
  },
  {
    // Komponen ui/ hasil shadcn tetap kena larangan float.
    files: ['src/components/ui/**/*.tsx'],
    rules: { 'no-restricted-syntax': noFloatMoney },
  },
);
