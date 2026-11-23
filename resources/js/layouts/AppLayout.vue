<template>
  <div
    class="flex h-screen overflow-hidden print:block print:h-auto print:overflow-visible bg-[var(--bg)] font-sans text-[var(--text)] transition-colors duration-150"
    :dir="$i18n.locale === 'ar' ? 'rtl' : 'ltr'"
  >
    <!-- Sidebar -->
    <SidebarArea class="print:hidden" :sidebar-open="sidebarOpen" @close-sidebar="sidebarOpen = false" />

    <!-- Main Content Column -->
    <div class="relative flex flex-1 flex-col overflow-y-auto overflow-x-hidden print:block print:h-auto print:overflow-visible">
      <!-- Header -->
      <HeaderArea class="print:hidden" @toggle-sidebar="sidebarOpen = !sidebarOpen" />

      <!-- Page Content -->
      <main class="grow p-3 sm:px-4 sm:py-3 md:px-5 md:py-4 print:p-0">
        <router-view />
      </main>
    </div>

    <!-- Toast notifications (top, never covering page actions) -->
    <ToastStack class="print:hidden" />
  </div>
</template>

<script setup>
import { ref } from 'vue';
import SidebarArea from '../components/tailadmin/SidebarArea.vue';
import HeaderArea from '../components/tailadmin/HeaderArea.vue';
import ToastStack from '../components/ui/ToastStack.vue';

const sidebarOpen = ref(false);
</script>
