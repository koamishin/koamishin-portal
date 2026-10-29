<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import ImpersonateBanner from '@/components/ImpersonateBanner.vue';
import { home } from '@/routes';

const page = usePage();
const name = page.props.name;

defineProps<{
    title?: string;
    description?: string;
    logoUrl?: string | null;
    applicationName?: string;
}>();
</script>

<template>
    <div
        class="relative grid h-dvh flex-col items-center justify-center px-8 sm:px-0 lg:max-w-none lg:grid-cols-2 lg:px-0"
    >
        <ImpersonateBanner class="absolute top-0 right-0 left-0 z-50" />
        <div
            class="relative hidden h-full flex-col bg-muted p-10 text-white lg:flex dark:border-r"
        >
            <div class="absolute inset-0 bg-zinc-900" />
            <Link
                :href="home()"
                class="relative z-20 flex items-center text-lg font-medium"
            >
                <img
                    v-if="logoUrl"
                    :src="logoUrl"
                    :alt="applicationName || 'Application'"
                    class="mr-2 max-h-12 max-w-32 object-contain"
                />
                <AppLogoIcon
                    v-else
                    class="mr-2 size-8 fill-current text-white"
                />
                {{ applicationName || name }}
            </Link>
        </div>
        <div class="lg:p-8">
            <div
                class="mx-auto flex w-full flex-col justify-center space-y-6 sm:w-[350px]"
            >
                <div class="flex flex-col space-y-2 text-center">
                    <img
                        v-if="logoUrl"
                        :src="logoUrl"
                        :alt="applicationName || 'Application'"
                        class="mx-auto mb-3 max-h-12 max-w-32 object-contain lg:hidden"
                    />
                    <h1 class="text-xl font-medium tracking-tight" v-if="title">
                        {{ title }}
                    </h1>
                    <p class="text-sm text-muted-foreground" v-if="description">
                        {{ description }}
                    </p>
                </div>
                <slot />
            </div>
        </div>
    </div>
</template>
