import { useState } from "react";
import { toast } from "sonner";
import { Button } from "@/Components/ui/Button";

interface Props { 
  licenseId: number;
}

export function SerialIssueForm({ licenseId }: Props) {
  const [prefixId, setPrefixId] = useState("");
  const [randomLength, setRandomLength] = useState("");
  const [idempotencyKey, setIdempotencyKey] = useState("");
  const [busy, setBusy] = useState(false);
  const [issued, setIssued] = useState<any>(null);

  const onSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    // Placeholder for real API call logic in the Inertia environment
    toast.info("Serial issuance porting in progress.", { 
      description: "Admin serial issuance endpoint needs to be wired in web.php" 
    });
    setBusy(false);
  };

  if (issued) {
    return (
      <div className="rounded-md border border-border bg-muted/40 p-4">
        <p className="text-xs font-medium text-muted-foreground">SERIAL ISSUED</p>
        <p className="mt-2 font-mono text-sm break-all">{issued.SerialValue}</p>
        <Button variant="outline" size="sm" className="mt-3" onClick={() => setIssued(null)}>
          Issue another
        </Button>
      </div>
    );
  }

  return (
    <form onSubmit={onSubmit} className="space-y-4 rounded-md border border-border bg-muted/30 p-4">
      <p className="text-xs font-medium text-muted-foreground uppercase tracking-wider">Issue serial for license #{licenseId}</p>
      
      <div className="space-y-2">
        <label className="text-sm font-medium">Prefix ID (optional)</label>
        <input 
          value={prefixId} 
          onChange={(e) => setPrefixId(e.target.value)}
          placeholder="e.g. 1"
          className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
        />
      </div>

      <div className="space-y-2">
        <label className="text-sm font-medium">Random length</label>
        <select 
          value={randomLength} 
          onChange={(e) => setRandomLength(e.target.value)}
          className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
        >
          <option value="">Server default</option>
          <option value="16">16</option>
          <option value="24">24</option>
          <option value="32">32</option>
        </select>
      </div>

      <Button type="submit" disabled={busy} className="w-full">
        {busy ? "Issuing..." : "Issue serial"}
      </Button>
    </form>
  );
}
