/**
 * Accessible custom <select> replacement. A styled trigger + a popup listbox we can
 * theme freely (native <select> options can't be styled cross-browser). Keyboard +
 * ARIA behave like a real listbox:
 *   - closed: Enter / Space / ↑ / ↓ open it
 *   - open:   ↑ / ↓ / Home / End move, Enter / Space pick, Esc / Tab close
 * Closes on outside click. Controlled — `value` in, `onChange(value)` out.
 * `placeholder` is shown when no option matches the value.
 */
import { useEffect, useRef, useState } from 'react';
import './custom-select.css';

export type SelectOption = { value: string; label: string };

export const CustomSelect = ({
  value,
  options,
  onChange,
  ariaLabel,
  placeholder,
}: {
  value: string;
  options: SelectOption[];
  onChange: (value: string) => void;
  ariaLabel?: string;
  placeholder?: string;
}) => {
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(0);
  const rootRef = useRef<HTMLDivElement>(null);
  const listRef = useRef<HTMLUListElement>(null);

  const selectedIndex = options.findIndex((o) => o.value === value);
  const selected = selectedIndex >= 0 ? options[selectedIndex] : undefined;
  const last = options.length - 1;

  // Close when clicking anywhere outside the component.
  useEffect(() => {
    if (!open) return;
    const onDown = (e: MouseEvent) => {
      if (rootRef.current && !rootRef.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', onDown);
    return () => document.removeEventListener('mousedown', onDown);
  }, [open]);

  // On open, highlight the current value (or the first option); keep it in view.
  useEffect(() => {
    if (!open) return;
    setActive(Math.max(0, selectedIndex));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);
  useEffect(() => {
    if (!open || !listRef.current) return;
    (listRef.current.children[active] as HTMLElement | undefined)?.scrollIntoView({ block: 'nearest' });
  }, [active, open]);
  // Keep `active` in range if the option list shrinks while open, so the highlight
  // never points past the end of the array.
  useEffect(() => {
    setActive((a) => Math.min(a, Math.max(0, options.length - 1)));
  }, [options.length]);

  const commit = (i: number) => {
    const opt = options[i];
    if (!opt) return;
    onChange(opt.value);
    setOpen(false);
  };

  const onKeyDown = (e: React.KeyboardEvent) => {
    if (!open) {
      if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        setOpen(true);
      }
      return;
    }
    switch (e.key) {
      case 'ArrowDown':
        e.preventDefault();
        if (active < last) setActive(active + 1);
        break;
      case 'ArrowUp':
        e.preventDefault();
        if (active > 0) setActive(active - 1);
        break;
      case 'Home':
        e.preventDefault();
        if (last >= 0) setActive(0);
        break;
      case 'End':
        e.preventDefault();
        if (last >= 0) setActive(last);
        break;
      case 'Enter':
      case ' ':
        e.preventDefault();
        commit(active);
        break;
      case 'Escape':
        e.preventDefault();
        setOpen(false);
        break;
      case 'Tab':
        setOpen(false);
        break;
    }
  };

  const triggerLabel = selected ? selected.label : placeholder ?? '—';

  return (
    <div className="fabrement-bb-cs" ref={rootRef}>
      <button
        type="button"
        className={`fabrement-bb-cs__trigger ${open ? 'is-open' : ''}`}
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-label={ariaLabel}
        onClick={() => setOpen((o) => !o)}
        onKeyDown={onKeyDown}
      >
        <span className={`fabrement-bb-cs__value ${selected ? '' : 'is-placeholder'}`}>{triggerLabel}</span>
        <span className="fabrement-bb-cs__chevron" aria-hidden="true" />
      </button>
      {open && (
        <ul className="fabrement-bb-cs__list" role="listbox" ref={listRef} tabIndex={-1}>
          {options.map((o, i) => (
            <li
              key={o.value || `opt-${i}`}
              role="option"
              aria-selected={o.value === value}
              className={['fabrement-bb-cs__opt', i === active ? 'is-active' : '', o.value === value ? 'is-selected' : '']
                .filter(Boolean)
                .join(' ')}
              onMouseEnter={() => setActive(i)}
              onMouseDown={(e) => {
                e.preventDefault(); // keep focus on the trigger; avoid blur-close race
                commit(i);
              }}
            >
              <span className="fabrement-bb-cs__opt-label">{o.label}</span>
              {o.value === value && <span className="fabrement-bb-cs__check" aria-hidden="true" />}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
};
