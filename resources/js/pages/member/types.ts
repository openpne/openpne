export type ProfileFormType =
    | 'input'
    | 'textarea'
    | 'select'
    | 'radio'
    | 'checkbox'
    | 'date'
    | 'country_select'
    | 'region_select';

export interface ProfileChoice {
    id: string;
    caption: string;
}

export interface RegionGroup {
    /** Country name when grouped (value_type='string'); '' for a single-country flat list. */
    country: string;
    options: Array<{ value: string; label: string }>;
}

export interface ProfileFormField {
    id: number;
    name: string;
    caption: string;
    info: string | null;
    form_type: ProfileFormType;
    is_required: boolean;
    is_edit_public_flag: boolean;
    options: ProfileChoice[];
    countries: Array<{ value: string; label: string }> | null;
    regions: RegionGroup[] | null;
    /** Current input: a list of option ids for a checkbox, otherwise a scalar string. */
    value: string | string[];
    visibility: number;
    visibilityOptions: Array<{ value: number; label: string }>;
}

export interface ProfileForm {
    name: string;
    fields: ProfileFormField[];
}

export interface SearchFormField {
    id: number;
    name: string;
    caption: string;
    // 'birthday' is the preset birthday rendered as a month/day picker (its year is searched via age).
    formType: ProfileFormType | 'birthday';
    options: ProfileChoice[];
    countries: Array<{ value: string; label: string }> | null;
    regions: RegionGroup[] | null;
}

// Type aliases (not interfaces) so they satisfy Inertia's FormDataConvertible index signature.
export type MonthDayRange = {
    from_month?: string;
    from_day?: string;
    to_month?: string;
    to_day?: string;
};

export type AgeRange = {
    min?: string;
    max?: string;
};

export interface MemberRow {
    id: number;
    name: string;
    avatarUrl: string | null;
}

export interface SearchCriteria {
    name: string;
    profile: Record<string, string | string[]>;
    date: Record<string, { from?: string; to?: string }>;
    monthday: Record<string, MonthDayRange>;
    age: AgeRange;
}
