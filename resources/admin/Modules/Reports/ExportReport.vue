<template>
  <el-button @click="exportCSV" size="small">
    <DynamicIcon name="Download"/>
    {{ $t("Export") }}
  </el-button>
</template>

<script>
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";

export default {
  props: {
    data: {
      type: Array,
      required: true,
    },
    filename: {
      type: String,
      default: "data.csv",
    },
  },
  components: {
    DynamicIcon
  },
  methods: {
    exportCSV() {
      const convertToCSV = (data) => {
        const formatHeader = (header) => {
          // Replace underscores with spaces and capitalize the words
          return header
            .replace(/_/g, " ")
            .replace(/\b\w/g, (char) => char.toUpperCase());
        };

        const keys = Object.keys(data[0]);
        const headers = keys.map(formatHeader);

        const csvRows = [headers.join(",")];

        data.forEach((row) => {
          const values = keys.map((key) => {
            let val = row[key];

            // If it's an object/array → stringify
            if (typeof val === "object") {
              val = JSON.stringify(val);
            }

            // Escape quotes & commas
            if (typeof val === "string") {
              val = `"${val.replace(/"/g, '""')}"`;
            }

            return val;
          });

          csvRows.push(values.join(","));
        });

        return csvRows.join("\n");
      };

      const csv = convertToCSV(this.data);
      const blob = new Blob([csv], { type: "text/csv" });
      const link = document.createElement("a");
      link.href = URL.createObjectURL(blob);
      link.download = this.filename;
      link.click();
      URL.revokeObjectURL(link.href);
    },
  },
};
</script>
