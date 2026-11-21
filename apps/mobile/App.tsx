import { formatIDR } from '@household-finance/shared';
import { StatusBar } from 'expo-status-bar';
import { StyleSheet, Text, View } from 'react-native';

// Smoke test #4: membuktikan Metro bisa me-resolve dan mentranspile packages/shared.
// Layar sungguhan (Expo Router, quick-add) dibuat di #64 dan #67.
export default function App() {
  return (
    <View style={styles.container}>
      <Text style={styles.title}>Household Finance</Text>
      <Text testID="shared-money">{formatIDR(1_250_000)}</Text>
      <StatusBar style="auto" />
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#fff', alignItems: 'center', justifyContent: 'center', gap: 8 },
  title: { fontSize: 20, fontWeight: '600' },
});
