<template>
  <div
    class="flex h-screen overflow-hidden bg-[var(--bg)] font-sans text-[var(--text)] transition-colors duration-150"
    :dir="$i18n.locale === 'ar' ? 'rtl' : 'ltr'"
  >
    <!-- Sidebar -->
    <SidebarArea :sidebar-open="sidebarOpen" @close-sidebar="sidebarOpen = false" />

    <!-- Main Content Column -->
    <div class="relative flex flex-1 flex-col overflow-y-auto overflow-x-hidden">
      <!-- Header -->
      <HeaderArea @toggle-sidebar="sidebarOpen = !sidebarOpen" />

      <!-- Page Content -->
      <main class="grow p-4 sm:p-6 md:p-8">
        <router-view />
      </main>
    </div>

    <!-- Toast notifications (top, never covering page actions) -->
    <ToastStack />
  </div>
</template>

<script setup>
import { ref } from 'vue';
import SidebarArea from '../components/tailadmin/SidebarArea.vue';
import HeaderArea from '../components/tailadmin/HeaderArea.vue';
import ToastStack from '../components/ui/ToastStack.vue';

const sidebarOpen = ref(false);
</script>
