import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { PasswordInput } from './PasswordInput';

describe('PasswordInput', () => {
  it('hides the password by default and toggles it with the eye button', async () => {
    const user = userEvent.setup();
    render(<><label htmlFor="pw">Kata sandi</label><PasswordInput id="pw" defaultValue="rahasia" /></>);
    const input = screen.getByLabelText('Kata sandi');

    expect(input).toHaveAttribute('type', 'password');
    await user.click(screen.getByRole('button', { name: 'Tampilkan kata sandi' }));
    expect(input).toHaveAttribute('type', 'text');
    expect(screen.getByRole('button', { name: 'Sembunyikan kata sandi' })).toHaveAttribute('aria-pressed', 'true');
    await user.click(screen.getByRole('button', { name: 'Sembunyikan kata sandi' }));
    expect(input).toHaveAttribute('type', 'password');
    expect(input).toHaveValue('rahasia');
  });

  it('does not submit the surrounding form', async () => {
    const user = userEvent.setup();
    const onSubmit = vi.fn((e: { preventDefault: () => void }) => e.preventDefault());
    render(<form onSubmit={onSubmit}><PasswordInput id="pw" /></form>);

    await user.click(screen.getByRole('button', { name: 'Tampilkan kata sandi' }));
    expect(onSubmit).not.toHaveBeenCalled();
  });
});
