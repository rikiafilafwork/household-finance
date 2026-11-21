import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { MoneyInput } from './MoneyInput';
import { MoneyText } from './MoneyText';

function Harness({ initial = null, allowNegative = false, spy }: { initial?: number | null; allowNegative?: boolean; spy: (v: number | null) => void }) {
  const [value, setValue] = useState<number | null>(initial);
  return (
    <MoneyInput
      aria-label="Nominal"
      value={value}
      allowNegative={allowNegative}
      onChange={(v) => {
        spy(v);
        setValue(v);
      }}
    />
  );
}

function setup(props: { initial?: number | null; allowNegative?: boolean } = {}) {
  const spy = vi.fn();
  render(<Harness {...props} spy={spy} />);
  const input = screen.getByLabelText('Nominal') as HTMLInputElement;
  return { spy, input, last: () => spy.mock.calls.at(-1)?.[0] };
}

function paste(input: HTMLInputElement, text: string) {
  input.focus();
  input.setSelectionRange(0, input.value.length);
  fireEvent.paste(input, { clipboardData: { getData: () => text } });
}

describe('MoneyInput', () => {
  it('formats with thousand dots while typing and emits integers', async () => {
    const { input, spy, last } = setup();

    await userEvent.type(input, '1250000');

    expect(input.value).toBe('1.250.000');
    expect(last()).toBe(1_250_000);
    for (const [v] of spy.mock.calls) expect(Number.isSafeInteger(v)).toBe(true); // tidak pernah float
  });

  it('ignores letters, commas and decimals while typing', async () => {
    const { input, last } = setup();

    await userEvent.type(input, '12a,5x0');

    expect(input.value).toBe('1.250');
    expect(last()).toBe(1250);
  });

  it('reads "1.250" as one thousand two hundred fifty, never 1.25', async () => {
    const { input, last } = setup();

    paste(input, '1.250');

    expect(last()).toBe(1250);
    expect(input.value).toBe('1.250');
  });

  it.each([
    ['Rp1.250.000', 1_250_000],
    ['Rp 1.250.000,00', 1_250_000],
    ['1250000', 1_250_000],
    ['rp50.000', 50_000],
  ])('parses pasted %s', (text, expected) => {
    const { input, last } = setup();

    paste(input, text);

    expect(last()).toBe(expected);
  });

  it.each(['1,5', '12.34', 'satu juta', '1.250,50'])('rejects pasted %s (keeps the old value)', (text) => {
    const { input, spy } = setup({ initial: 7000 });

    paste(input, text);

    expect(spy).not.toHaveBeenCalled();
    expect(input.value).toBe('7.000');
  });

  it('emits null when cleared', async () => {
    const { input, last } = setup({ initial: 5000 });

    await userEvent.clear(input);

    expect(last()).toBeNull();
    expect(input.value).toBe('');
  });

  it('strips leading zeros', async () => {
    const { input, last } = setup();

    await userEvent.type(input, '000500');

    expect(last()).toBe(500);
    expect(input.value).toBe('500');
  });

  it('ignores minus unless allowNegative', async () => {
    const plain = setup();
    await userEvent.type(plain.input, '-500');
    expect(plain.last()).toBe(500);
  });

  it('supports negative values when allowed (opening balance / adjustment)', async () => {
    const { input, last } = setup({ allowNegative: true });

    await userEvent.type(input, '-2500000');

    expect(input.value).toBe('-2.500.000');
    expect(last()).toBe(-2_500_000);
  });

  it('rejects input above MAX_AMOUNT', async () => {
    const { input, last } = setup({ initial: 1_000_000_000_000_000 });
    input.focus();
    input.setSelectionRange(input.value.length, input.value.length);

    await userEvent.type(input, '1');

    expect(last()).toBe(1_000_000_000_000_000);
    expect(input.value).toBe('1.000.000.000.000.000');
  });

  it('keeps the caret after the same digit when dots shift', async () => {
    const { input } = setup({ initial: 125000 });
    input.focus();
    input.setSelectionRange(1, 1); // "1|25.000"

    await userEvent.keyboard('9');

    expect(input.value).toBe('1.925.000');
    expect(input.selectionStart).toBe(3); // "1.9|25.000"
  });
});

describe('MoneyText', () => {
  it('formats plain, signed, and balance tones', () => {
    render(
      <>
        <MoneyText amount={1_250_000} />
        <MoneyText amount={50_000} tone="signed" />
        <MoneyText amount={-50_000} tone="signed" />
        <MoneyText amount={-1} tone="balance" />
      </>,
    );

    expect(screen.getByText('Rp1.250.000')).toBeInTheDocument();
    expect(screen.getByText('+Rp50.000')).toHaveClass('text-success');
    expect(screen.getByText('−Rp50.000')).not.toHaveClass('text-success');
    expect(screen.getByText('−Rp1')).toHaveClass('text-destructive');
  });
});
