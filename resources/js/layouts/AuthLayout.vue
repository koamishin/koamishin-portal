<script setup lang="ts">
import { computed, onMounted, watchEffect } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AuthSimpleLayout from '@/layouts/auth/AuthSimpleLayout.vue';
import AuthCardLayout from '@/layouts/auth/AuthCardLayout.vue';
import AuthSplitLayout from '@/layouts/auth/AuthSplitLayout.vue';

defineProps<{
    title?: string;
    description?: string;
}>();

const page = usePage();
onMounted(() => {
    watchEffect(() => {
        const screens: Record<string, string> = {
            'auth/Login': 'login',
            'auth/Register': 'register',
            'auth/TwoFactorChallenge': 'two-factor',
            'auth/VerifyEmail': 'verify',
            'auth/oauth/Authorize': 'continue',
        };
        const screen = screens[page.component];
        if (
            page.props.hosted &&
            typeof page.props.flow === 'string' &&
            screen
        ) {
            try {
                sessionStorage.setItem(
                    'koamishin.hosted',
                    JSON.stringify({ flow: page.props.flow, screen }),
                );
            } catch {
                // The current form can still be submitted without session storage.
            }
        }
    });
});
const application = computed(
    () =>
        page.props.application as {
            logoUrl?: string | null;
            name?: string;
        } | null,
);

const layoutComponent = computed(() => {
    const layout = page.props.authLayout ?? 'simple';

    switch (layout) {
        case 'card':
            return AuthCardLayout;
        case 'split':
            return AuthSplitLayout;
        default:
            return AuthSimpleLayout;
    }
});
</script>

<template>
    <component
        :is="layoutComponent"
        :title="title"
        :description="description"
        :logo-url="application?.logoUrl"
        :application-name="application?.name"
    >
        <slot />
    </component>
</template>
