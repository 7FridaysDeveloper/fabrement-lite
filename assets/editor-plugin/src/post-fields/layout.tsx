import { FieldsGrid } from './controls';
import type { State, Values } from './types';
import type { FieldIntent } from '../post-fields-save';

const { useState, useRef } = wp.element;

/** Layout tabs stay mounted so their controls keep drafts and selection. */
export function FieldsLayout({id, state, values, onChange}: {id: string; state: State; values: Values; onChange: (values: Values, intent?: FieldIntent) => void}) {
    const [activeTab, setActiveTab] = useState(0);
    const tabsRef = useRef<HTMLDivElement | null>(null);
    const fields = state.fields;
    const tabs = state.layout.tabs.filter(tab => tab.items.length);
    const placed = new Set(tabs.flatMap(tab => tab.items.map(item => item.field)));
    const rest = fields.filter(field => !placed.has(field.key));
    const current = Math.min(activeTab, Math.max(tabs.length - 1, 0));
    const tabId = (i: number) => 'fabrement-fields-' + id + '-tab-' + i;
    const panelId = (i: number) => 'fabrement-fields-' + id + '-panel-' + i;
    const onTabKey = (event: any, index: number) => {
        const rtl = tabsRef.current && getComputedStyle(tabsRef.current).direction === 'rtl';
        const moves: Record<string, number> = {ArrowRight: rtl ? -1 : 1, ArrowLeft: rtl ? 1 : -1};
        let next: number;
        if (event.key in moves) next = (index + moves[event.key] + tabs.length) % tabs.length;
        else if (event.key === 'Home') next = 0;
        else if (event.key === 'End') next = tabs.length - 1;
        else return;
        event.preventDefault();
        setActiveTab(next);
        tabsRef.current?.querySelectorAll<HTMLButtonElement>('[role="tab"]')[next]?.focus();
    };

    return <>
        {tabs.length > 1 && <div ref={tabsRef} role="tablist" aria-label={__('Field tabs', 'fabrement-lite')} className="fabrement-post-fields__tabs">
            {tabs.map((tab, index) => <button key={index} type="button" role="tab" className="fabrement-post-fields__tab"
                id={tabId(index)} aria-selected={current === index} aria-controls={panelId(index)} tabIndex={current === index ? 0 : -1}
                onClick={() => setActiveTab(index)} onKeyDown={(event: any) => onTabKey(event, index)}>{tab.label}</button>)}
        </div>}
        {/* Panels stay mounted so switching tabs keeps control-local drafts and selection. */}
        {tabs.map((tab, index) => <section key={index} className="fabrement-post-fields__panel" id={panelId(index)}
            role={tabs.length > 1 ? 'tabpanel' : undefined} aria-labelledby={tabs.length > 1 ? tabId(index) : undefined}
            hidden={tabs.length > 1 && current !== index}>
            {tabs.length === 1 && <h3>{tab.label}</h3>}
            <FieldsGrid fields={fields} items={tab.items} values={values} onChange={onChange} />
        </section>)}
        {rest.length > 0 && <section className={tabs.length ? 'fabrement-post-fields__rest' : ''}>
            <FieldsGrid auto fields={rest} values={values} onChange={onChange} />
        </section>}
    </>;
}
