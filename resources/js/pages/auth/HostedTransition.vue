<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { store } from '@/routes/hosted';

const props = defineProps<{
    flow: string | null;
    screen: string | null;
    csrfToken: string;
    restore: boolean;
}>();

const form = ref<HTMLFormElement | null>(null);
const flow = ref(props.flow ?? '');
const screen = ref(props.screen ?? '');
const expired = ref(false);

onMounted(() => {
    try {
        if (props.restore) {
            const saved = JSON.parse(
                sessionStorage.getItem('koamishin.hosted') ?? 'null',
            );
            if (
                !saved ||
                typeof saved.flow !== 'string' ||
                typeof saved.screen !== 'string'
            ) {
                expired.value = true;
                return;
            }
            flow.value = saved.flow;
            screen.value = saved.screen;
        } else {
            sessionStorage.setItem(
                'koamishin.hosted',
                JSON.stringify({ flow: flow.value, screen: screen.value }),
            );
        }
    } catch {
        if (props.restore) {
            expired.value = true;
            return;
        }
    }

    requestAnimationFrame(() => form.value?.submit());
});
</script>

<template>
    <div>
        <p v-if="expired">
            Your sign-in has expired. Start again from the application.
        </p>
        <form v-else ref="form" method="post" :action="store.url()">
            <input type="hidden" name="_token" :value="csrfToken" />
            <input type="hidden" name="flow" :value="flow" />
            <input type="hidden" name="screen" :value="screen" />
            <input type="hidden" name="hosted" value="1" />
            <button type="submit">Continue</button>
        </form>
    </div>
</template>
